<?php

namespace Tests\Feature\Fil;

use App\Models\Commentaire;
use App\Models\Follow;
use App\Models\Post;
use App\Models\Story;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FilActualiteTest extends TestCase
{
    use RefreshDatabase;

    private function publication(User $auteur, string $description = 'Une réalisation.'): Post
    {
        return Post::create([
            'artisan_id' => $auteur->id,
            'artisan_category' => 'Menuisier',
            'image_url' => 'https://exemple.cg/photo.jpg',
            'description' => $description,
        ]);
    }

    // ------------------------------------------------------------------
    // Fil
    // ------------------------------------------------------------------

    public function test_le_fil_remonte_les_publications_avec_leur_auteur(): void
    {
        $artisan = User::factory()->artisan()->create(['nom' => 'Nkodia', 'prenom' => 'Pascal']);
        $this->publication($artisan, 'Buffet en bois massif.');

        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/v1/posts')
            ->assertOk()
            ->assertJsonPath('data.0.auteur.nomComplet', 'Pascal Nkodia')
            ->assertJsonPath('data.0.description', 'Buffet en bois massif.')
            ->assertJsonPath('data.0.isLiked', false)
            ->assertJsonPath('data.0.likesCount', 0);
    }

    public function test_le_fil_par_abonnements_ne_montre_que_les_artisans_suivis(): void
    {
        $client = User::factory()->create();
        $suivi = User::factory()->artisan()->create();
        $inconnu = User::factory()->artisan()->create();

        $this->publication($suivi, 'Publication suivie.');
        $this->publication($inconnu, 'Publication non suivie.');

        Follow::create(['follower_id' => $client->id, 'artisan_id' => $suivi->id]);

        Sanctum::actingAs($client);

        $descriptions = collect($this->getJson('/api/v1/posts?abonnements=1')->json('data'))
            ->pluck('description');

        $this->assertTrue($descriptions->contains('Publication suivie.'));
        $this->assertFalse($descriptions->contains('Publication non suivie.'));
    }

    public function test_sans_abonnement_le_fil_montre_toute_la_plateforme(): void
    {
        $artisan = User::factory()->artisan()->create();
        $this->publication($artisan);

        Sanctum::actingAs(User::factory()->create());

        // Un fil vide à la première ouverture ne donne aucune raison de revenir.
        $this->getJson('/api/v1/posts?abonnements=1')->assertOk()->assertJsonCount(1, 'data');
    }

    // ------------------------------------------------------------------
    // Likes
    // ------------------------------------------------------------------

    public function test_le_like_se_bascule_et_met_a_jour_le_compteur(): void
    {
        $post = $this->publication(User::factory()->artisan()->create());
        $client = User::factory()->create();

        Sanctum::actingAs($client);

        $this->postJson("/api/v1/posts/{$post->id}/like")
            ->assertOk()
            ->assertJsonPath('aime', true)
            ->assertJsonPath('likesCount', 1);

        $this->getJson('/api/v1/posts')->assertJsonPath('data.0.isLiked', true);

        $this->postJson("/api/v1/posts/{$post->id}/like")
            ->assertOk()
            ->assertJsonPath('aime', false)
            ->assertJsonPath('likesCount', 0);

        $this->getJson('/api/v1/posts')->assertJsonPath('data.0.isLiked', false);
    }

    public function test_le_like_d_une_publication_n_affecte_pas_les_autres(): void
    {
        $artisan = User::factory()->artisan()->create();
        $cible = $this->publication($artisan, 'Cible');
        $temoin = $this->publication($artisan, 'Témoin');

        $client = User::factory()->create();
        Sanctum::actingAs($client);

        $this->postJson("/api/v1/posts/{$cible->id}/like");
        $this->postJson("/api/v1/posts/{$temoin->id}/like");

        // Retirer un like ne doit décrémenter que la publication concernée.
        $this->postJson("/api/v1/posts/{$cible->id}/like");

        $this->assertSame(0, $cible->fresh()->likes_count);
        $this->assertSame(1, $temoin->fresh()->likes_count, 'Le décrément a débordé sur une autre publication.');
    }

    public function test_le_compteur_de_likes_est_propre_a_chaque_utilisateur(): void
    {
        $post = $this->publication(User::factory()->artisan()->create());

        $premier = User::factory()->create();
        $second = User::factory()->create();

        Sanctum::actingAs($premier);
        $this->postJson("/api/v1/posts/{$post->id}/like");

        Sanctum::actingAs($second);
        $this->getJson('/api/v1/posts')->assertJsonPath('data.0.isLiked', false);
        $this->postJson("/api/v1/posts/{$post->id}/like")->assertJsonPath('likesCount', 2);
    }

    // ------------------------------------------------------------------
    // Commentaires
    // ------------------------------------------------------------------

    public function test_un_commentaire_est_publie_et_compte(): void
    {
        $post = $this->publication(User::factory()->artisan()->create());
        $client = User::factory()->create(['nom' => 'Makaya', 'prenom' => 'Jean']);

        Sanctum::actingAs($client);

        $this->postJson("/api/v1/posts/{$post->id}/commentaires", ['contenu' => 'Très beau travail.'])
            ->assertCreated()
            ->assertJsonPath('commentaire.contenu', 'Très beau travail.')
            ->assertJsonPath('commentaire.auteur.nomComplet', 'Jean Makaya');

        $this->assertSame(1, $post->fresh()->comments_count);

        $this->getJson("/api/v1/posts/{$post->id}/commentaires")
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_un_commentaire_ne_se_supprime_que_par_son_auteur(): void
    {
        $post = $this->publication(User::factory()->artisan()->create());
        $auteur = User::factory()->create();

        $commentaire = Commentaire::create([
            'post_id' => $post->id,
            'user_id' => $auteur->id,
            'contenu' => 'Mon commentaire.',
        ]);
        $post->increment('comments_count');

        Sanctum::actingAs(User::factory()->create());
        $this->deleteJson("/api/v1/commentaires/{$commentaire->id}")->assertForbidden();

        Sanctum::actingAs($auteur);
        $this->deleteJson("/api/v1/commentaires/{$commentaire->id}")->assertOk();

        $this->assertSame(0, $post->fresh()->comments_count);
    }

    // ------------------------------------------------------------------
    // Publications et stories
    // ------------------------------------------------------------------

    public function test_une_publication_accepte_plusieurs_photos(): void
    {
        Storage::fake('public');

        $png = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

        Sanctum::actingAs(User::factory()->artisan()->create());

        $this->postJson('/api/v1/posts', [
            'artisan_category' => 'Menuisier',
            'description' => 'Trois vues du buffet terminé.',
            'medias' => [$png, $png, $png],
        ])->assertCreated()->assertJsonCount(3, 'post.medias');

        $this->assertCount(3, Storage::disk('public')->allFiles('publications'));
    }

    public function test_une_publication_ne_se_supprime_que_par_son_auteur(): void
    {
        $auteur = User::factory()->artisan()->create();
        $post = $this->publication($auteur);

        Sanctum::actingAs(User::factory()->artisan()->create());
        $this->deleteJson("/api/v1/posts/{$post->id}")->assertForbidden();

        Sanctum::actingAs($auteur);
        $this->deleteJson("/api/v1/posts/{$post->id}")->assertOk();
        $this->assertDatabaseCount('posts', 0);
    }

    public function test_une_story_expire_au_bout_de_24_heures(): void
    {
        Storage::fake('public');

        $artisan = User::factory()->artisan()->create();
        Sanctum::actingAs($artisan);

        $this->postJson('/api/v1/stories', [
            'media_url' => 'https://exemple.cg/story.jpg',
            'legende' => 'Chantier du jour',
        ])->assertCreated();

        $this->getJson('/api/v1/stories')->assertOk()->assertJsonCount(1, 'data');

        $this->travel(25)->hours();

        $this->getJson('/api/v1/stories')->assertOk()->assertJsonCount(0, 'data');
        $this->assertSame(1, Story::count(), 'La story doit rester en base, seulement masquée.');
    }

    // ------------------------------------------------------------------
    // Abonnements
    // ------------------------------------------------------------------

    public function test_l_abonnement_a_un_artisan_se_bascule(): void
    {
        $artisan = User::factory()->artisan()->create();
        $client = User::factory()->create();

        Sanctum::actingAs($client);

        $this->postJson("/api/v1/suivre/{$artisan->id}")->assertCreated()->assertJsonPath('abonne', true);
        $this->postJson("/api/v1/suivre/{$artisan->id}")->assertOk()->assertJsonPath('abonne', false);
    }

    public function test_on_ne_peut_pas_se_suivre_soi_meme(): void
    {
        $artisan = User::factory()->artisan()->create();

        Sanctum::actingAs($artisan);

        $this->postJson("/api/v1/suivre/{$artisan->id}")->assertStatus(422);
    }
}
