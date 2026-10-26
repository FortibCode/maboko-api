<?php

namespace Tests\Feature\Admin;

use App\Models\Artisan;
use App\Models\Avis;
use App\Models\DemandeDevis;
use App\Models\Litige;
use App\Models\Post;
use App\Models\Report;
use App\Models\User;
use App\Models\VerificationIdentite;
use App\Services\ClassementArtisan;
use App\Services\MediaService;
use Database\Seeders\BadgeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ModerationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(BadgeSeeder::class);
        Storage::fake('local');

        $this->admin = User::factory()->admin()->create();
        Sanctum::actingAs($this->admin);
    }

    private function publicationSignalee(): array
    {
        $auteur = User::factory()->artisan()->create();
        $post = Post::create([
            'artisan_id' => $auteur->id,
            'artisan_category' => 'Menuisier',
            'image_url' => 'https://exemple.cg/photo.jpg',
            'description' => 'Contenu contesté.',
        ]);

        $signalement = Report::create([
            'target_id' => (string) $post->id,
            'target_type' => 'post',
            'reason' => 'Contenu inapproprié',
            'reporter_id' => User::factory()->create()->id,
        ]);

        return [$post, $signalement];
    }

    // ------------------------------------------------------------------
    // Signalements (§5.4.2)
    // ------------------------------------------------------------------

    public function test_la_file_joint_le_contenu_vise(): void
    {
        [$post] = $this->publicationSignalee();

        // L'administrateur décide sur pièce, pas sur un identifiant.
        $this->getJson('/api/v1/admin/signalements')
            ->assertOk()
            ->assertJsonPath('data.0.motif', 'Contenu inapproprié')
            ->assertJsonPath('data.0.contenu.description', $post->description)
            ->assertJsonPath('data.0.contenuSupprime', false);
    }

    public function test_la_suppression_retire_la_publication(): void
    {
        [$post, $signalement] = $this->publicationSignalee();

        $this->postJson("/api/v1/admin/signalements/{$signalement->id}", ['decision' => 'supprimer'])
            ->assertOk();

        $this->assertDatabaseMissing('posts', ['id' => $post->id]);
        $this->assertSame('traite', $signalement->fresh()->statut);
        $this->assertSame($this->admin->id, $signalement->fresh()->traite_par);
    }

    public function test_le_classement_sans_suite_conserve_la_publication(): void
    {
        [$post, $signalement] = $this->publicationSignalee();

        $this->postJson("/api/v1/admin/signalements/{$signalement->id}", [
            'decision' => 'ignorer',
            'commentaire' => 'Rien de contraire aux règles.',
        ])->assertOk();

        $this->assertDatabaseHas('posts', ['id' => $post->id]);
        $this->assertSame('ignore', $signalement->fresh()->statut);
    }

    public function test_masquer_un_avis_recalcule_la_note_de_l_artisan(): void
    {
        $artisan = Artisan::factory()->create();
        $client = User::factory()->create();
        $demande = DemandeDevis::factory()->statut(DemandeDevis::STATUT_TERMINEE)
            ->create(['client_id' => $client->id, 'artisan_id' => $artisan->id]);

        $avis = Avis::create([
            'auteur_id' => $client->id,
            'artisan_id' => $artisan->id,
            'demande_devis_id' => $demande->id,
            'note' => 1,
        ]);

        app(ClassementArtisan::class)->recalculer($artisan);
        $this->assertSame(1, $artisan->fresh()->nb_avis);

        $this->postJson("/api/v1/admin/avis/{$avis->id}/masquer")->assertOk();

        // Un avis abusif ne doit plus peser sur la note.
        $this->assertSame(0, $artisan->fresh()->nb_avis);
    }

    // ------------------------------------------------------------------
    // Vérification d'identité (§4.5)
    // ------------------------------------------------------------------

    public function test_la_validation_d_identite_accorde_le_badge_profil_verifie(): void
    {
        $artisan = Artisan::factory()->create();
        $verification = VerificationIdentite::create([
            'user_id' => $artisan->utilisateur_id,
            'type_piece' => 'cni',
            'chemin_recto' => 'identites/recto.jpg',
            'statut' => 'en_attente',
        ]);

        $this->postJson("/api/v1/admin/verifications/{$verification->id}", ['decision' => 'valide'])
            ->assertOk();

        $this->assertSame('valide', $verification->fresh()->statut);
        $this->assertTrue($artisan->badges()->where('slug', 'profil-verifie')->exists());
    }

    public function test_un_refus_de_verification_exige_un_motif(): void
    {
        $verification = VerificationIdentite::create([
            'user_id' => User::factory()->create()->id,
            'type_piece' => 'cni',
            'chemin_recto' => 'identites/recto.jpg',
            'statut' => 'en_attente',
        ]);

        $this->postJson("/api/v1/admin/verifications/{$verification->id}", ['decision' => 'rejete'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('motif');
    }

    public function test_les_pieces_ne_sont_servies_que_par_un_lien_signe(): void
    {
        // Les pièces sont chiffrées au repos : on passe par le service pour
        // écrire un fichier conforme à ce que produit le dépôt réel.
        $png = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';
        $chemin = app(MediaService::class)->enregistrerPiecePrivee($png, 'identites');

        $verification = VerificationIdentite::create([
            'user_id' => User::factory()->create()->id,
            'type_piece' => 'cni',
            'chemin_recto' => $chemin,
            'statut' => 'en_attente',
        ]);

        $lien = $this->getJson('/api/v1/admin/verifications')->json('data.0.pieces.recto');
        $this->assertNotEmpty($lien);
        $this->assertStringContainsString('signature=', $lien);

        // Le lien signé fonctionne.
        $this->get($lien)->assertOk();

        // Sans signature, l'accès est refusé même pour un administrateur.
        $this->get("/api/v1/admin/pieces/{$verification->id}/recto")->assertForbidden();
    }

    // ------------------------------------------------------------------
    // Litiges (§3.4)
    // ------------------------------------------------------------------

    public function test_un_litige_se_traite_et_se_resout(): void
    {
        $litige = Litige::create([
            'ouvert_par' => User::factory()->create()->id,
            'motif' => 'Travail non conforme',
            'description' => 'L’artisan n’a pas terminé le chantier.',
            'statut' => 'ouvert',
        ]);

        $this->getJson('/api/v1/admin/litiges')->assertOk()->assertJsonCount(1, 'data');

        $this->postJson("/api/v1/admin/litiges/{$litige->id}", [
            'statut' => 'resolu',
            'resolution' => 'Remboursement partiel accordé.',
        ])->assertOk();

        $litige->refresh();
        $this->assertSame('resolu', $litige->statut);
        $this->assertSame($this->admin->id, $litige->assigne_a);
        $this->assertNotNull($litige->resolu_at);
    }

    public function test_la_moderation_est_reservee_a_l_administration(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/v1/admin/signalements')->assertForbidden();
        $this->getJson('/api/v1/admin/verifications')->assertForbidden();
        $this->getJson('/api/v1/admin/litiges')->assertForbidden();
    }
}
