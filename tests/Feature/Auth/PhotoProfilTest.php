<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Photo de profil reelle.
 *
 * La colonne « avatar_url » n'etait jamais ecrite : aucune route ne le
 * permettait, et l'application affichait une icone tiree d'une liste figee.
 */
class PhotoProfilTest extends TestCase
{
    use RefreshDatabase;

    /** Le plus petit PNG valide possible, pour ne pas dependre d'un fichier. */
    private const PNG =
        'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAAC0lEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    public function test_un_utilisateur_depose_sa_photo_de_profil(): void
    {
        $utilisateur = User::factory()->create(['avatar_url' => null]);
        Sanctum::actingAs($utilisateur);

        $reponse = $this->postJson('/api/v1/compte/avatar', ['photo' => self::PNG])
            ->assertOk()
            ->assertJsonStructure(['message', 'avatarUrl']);

        $this->assertNotNull($utilisateur->fresh()->avatar_url);
        $this->assertSame($utilisateur->fresh()->avatar_url, $reponse->json('avatarUrl'));
    }

    public function test_deposer_une_nouvelle_photo_remplace_la_precedente(): void
    {
        $utilisateur = User::factory()->create(['avatar_url' => null]);
        Sanctum::actingAs($utilisateur);

        $this->postJson('/api/v1/compte/avatar', ['photo' => self::PNG])->assertOk();
        $premiere = $utilisateur->fresh()->avatar_url;

        $this->postJson('/api/v1/compte/avatar', ['photo' => self::PNG])->assertOk();
        $seconde = $utilisateur->fresh()->avatar_url;

        $this->assertNotSame($premiere, $seconde);
    }

    public function test_un_contenu_qui_n_est_pas_une_image_est_refuse(): void
    {
        $utilisateur = User::factory()->create(['avatar_url' => null]);
        Sanctum::actingAs($utilisateur);

        $this->postJson('/api/v1/compte/avatar', ['photo' => 'bonjour'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('photo');

        $this->assertNull($utilisateur->fresh()->avatar_url);
    }

    public function test_un_format_d_image_non_autorise_est_refuse(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/v1/compte/avatar', [
            'photo' => 'data:image/gif;base64,R0lGODlhAQABAAAAACw=',
        ])->assertStatus(422);
    }

    public function test_la_photo_peut_etre_retiree(): void
    {
        $utilisateur = User::factory()->create();
        Sanctum::actingAs($utilisateur);

        $this->postJson('/api/v1/compte/avatar', ['photo' => self::PNG])->assertOk();
        $this->assertNotNull($utilisateur->fresh()->avatar_url);

        $this->deleteJson('/api/v1/compte/avatar')
            ->assertOk()
            ->assertJsonPath('avatarUrl', null);

        $this->assertNull($utilisateur->fresh()->avatar_url);
    }

    public function test_le_depot_exige_une_authentification(): void
    {
        $this->postJson('/api/v1/compte/avatar', ['photo' => self::PNG])->assertUnauthorized();
    }

    public function test_un_utilisateur_ne_touche_pas_a_la_photo_d_un_autre(): void
    {
        $autre = User::factory()->create(['avatar_url' => null]);
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/v1/compte/avatar', ['photo' => self::PNG])->assertOk();

        $this->assertNull($autre->fresh()->avatar_url);
    }

    public function test_un_utilisateur_modifie_son_nom_et_sa_ville(): void
    {
        $utilisateur = User::factory()->create(['nom' => 'Ancien', 'ville' => null]);
        Sanctum::actingAs($utilisateur);

        $this->patchJson('/api/v1/compte', ['nom' => 'Nouveau', 'ville' => 'Brazzaville'])
            ->assertOk()
            ->assertJsonPath('user.nom', 'Nouveau')
            ->assertJsonPath('user.ville', 'Brazzaville');

        $this->assertSame('Nouveau', $utilisateur->fresh()->nom);
    }

    public function test_le_role_ne_peut_pas_etre_change_par_le_titulaire(): void
    {
        $utilisateur = User::factory()->create(['role' => User::ROLE_CLIENT]);
        Sanctum::actingAs($utilisateur);

        $this->patchJson('/api/v1/compte', ['nom' => 'Test', 'role' => User::ROLE_ADMIN])->assertOk();

        $this->assertSame(User::ROLE_CLIENT, $utilisateur->fresh()->role);
    }

    public function test_un_email_deja_pris_est_refuse(): void
    {
        User::factory()->create(['email' => 'occupe@maboko.cg']);
        Sanctum::actingAs(User::factory()->create());

        $this->patchJson('/api/v1/compte', ['email' => 'occupe@maboko.cg'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');
    }

    public function test_conserver_son_propre_email_est_accepte(): void
    {
        $utilisateur = User::factory()->create(['email' => 'moi@maboko.cg']);
        Sanctum::actingAs($utilisateur);

        $this->patchJson('/api/v1/compte', ['email' => 'moi@maboko.cg', 'nom' => 'Moi'])->assertOk();
    }
}
