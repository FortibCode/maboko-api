<?php

namespace Tests\Feature\Chauffeur;

use App\Models\Chauffeur;
use App\Models\User;
use App\Models\VerificationIdentite;
use App\Services\MediaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Depot de la fiche vehicule par le chauffeur (§5.3.1).
 *
 * Avant cet endpoint, seul le seeder creait des lignes « chauffeurs » : un
 * compte inscrit depuis l'application restait inutilisable a vie.
 */
class DepotFicheChauffeurTest extends TestCase
{
    use RefreshDatabase;

    private const PNG = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    private function chauffeurSansFiche(): User
    {
        return User::factory()->create(['role' => User::ROLE_CHAUFFEUR]);
    }

    private function fiche(array $remplacements = []): array
    {
        return array_merge([
            'type_vehicule' => 'moto',
            'vehicule_modele' => 'Yamaha Crux',
            'plaque_immatriculation' => 'BZV-777-CG',
            'permis_conduire' => 'PERM-77001',
        ], $remplacements);
    }

    public function test_un_chauffeur_sans_fiche_peut_deposer_la_sienne(): void
    {
        $utilisateur = $this->chauffeurSansFiche();
        Sanctum::actingAs($utilisateur);

        $this->postJson('/api/v1/chauffeur', $this->fiche())
            ->assertCreated()
            ->assertJsonPath('ficheManquante', false)
            ->assertJsonPath('typeVehicule', 'moto')
            ->assertJsonPath('plaque', 'BZV-777-CG')
            ->assertJsonPath('statutValidation', Chauffeur::VALIDATION_EN_ATTENTE);

        $this->assertDatabaseHas('chauffeurs', [
            'utilisateur_id' => $utilisateur->id,
            'plaque_immatriculation' => 'BZV-777-CG',
            'statut_validation' => Chauffeur::VALIDATION_EN_ATTENTE,
        ]);
    }

    public function test_la_piece_d_identite_rejoint_la_verification_chiffree(): void
    {
        Storage::fake('local');
        $utilisateur = $this->chauffeurSansFiche();
        Sanctum::actingAs($utilisateur);

        $this->postJson('/api/v1/chauffeur', $this->fiche(['photo_recto' => self::PNG, 'photo_verso' => self::PNG]))
            ->assertCreated()
            ->assertJsonMissingPath('photo_recto');

        $verification = VerificationIdentite::where('user_id', $utilisateur->id)->sole();

        $this->assertSame('en_attente', $verification->statut);
        $this->assertStringEndsWith('.chiffre', $verification->chemin_recto);
        $this->assertStringEndsWith('.chiffre', $verification->chemin_verso);
        $this->assertSame(
            base64_decode(explode(',', self::PNG)[1], true),
            app(MediaService::class)->lirePiecePrivee($verification->chemin_verso),
        );
    }

    public function test_renvoyer_la_fiche_ne_relance_pas_la_verification(): void
    {
        Storage::fake('local');
        $utilisateur = $this->chauffeurSansFiche();
        Sanctum::actingAs($utilisateur);
        $fiche = $this->fiche(['photo_recto' => self::PNG, 'photo_verso' => self::PNG]);

        $this->postJson('/api/v1/chauffeur', $fiche)->assertCreated();
        $this->postJson('/api/v1/chauffeur', $fiche)->assertOk();

        $this->assertSame(1, VerificationIdentite::where('user_id', $utilisateur->id)->count());
    }

    public function test_une_photo_illisible_ne_cree_pas_de_fiche(): void
    {
        Storage::fake('local');
        $utilisateur = $this->chauffeurSansFiche();
        Sanctum::actingAs($utilisateur);

        $this->postJson('/api/v1/chauffeur', $this->fiche(['photo_recto' => 'pas-une-image', 'photo_verso' => self::PNG]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('photo_recto');

        $this->assertDatabaseMissing('chauffeurs', ['utilisateur_id' => $utilisateur->id]);
        $this->assertDatabaseCount('verifications_identite', 0);
    }

    public function test_le_recto_seul_est_refuse(): void
    {
        Sanctum::actingAs($this->chauffeurSansFiche());

        $this->postJson('/api/v1/chauffeur', $this->fiche(['photo_recto' => self::PNG]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('photo_verso');
    }

    public function test_le_chauffeur_declare_un_vehicule_climatise(): void
    {
        $utilisateur = $this->chauffeurSansFiche();
        Sanctum::actingAs($utilisateur);

        $this->postJson('/api/v1/chauffeur', $this->fiche(['est_climatise' => true]))
            ->assertCreated()
            ->assertJsonPath('climatise', true);

        $this->getJson('/api/v1/chauffeur')->assertJsonPath('climatise', true);
        $this->assertDatabaseHas('chauffeurs', ['utilisateur_id' => $utilisateur->id, 'est_climatise' => true]);
    }

    public function test_sans_precision_le_vehicule_n_est_pas_climatise(): void
    {
        Sanctum::actingAs($this->chauffeurSansFiche());

        $this->postJson('/api/v1/chauffeur', $this->fiche())
            ->assertCreated()
            ->assertJsonPath('climatise', false);
    }

    public function test_la_fiche_deposee_n_est_pas_immediatement_en_ligne(): void
    {
        Sanctum::actingAs($this->chauffeurSansFiche());

        $this->postJson('/api/v1/chauffeur', $this->fiche())
            ->assertCreated()
            ->assertJsonPath('enLigne', false);
    }

    public function test_apres_depot_la_fiche_est_lisible_et_ne_renvoie_plus_404(): void
    {
        Sanctum::actingAs($this->chauffeurSansFiche());

        $this->getJson('/api/v1/chauffeur')->assertNotFound();

        $this->postJson('/api/v1/chauffeur', $this->fiche())->assertCreated();

        $this->getJson('/api/v1/chauffeur')
            ->assertOk()
            ->assertJsonPath('ficheManquante', false);
    }

    public function test_modifier_son_vehicule_repasse_la_fiche_en_attente(): void
    {
        $chauffeur = Chauffeur::factory()->create([
            'statut_validation' => Chauffeur::VALIDATION_VALIDE,
            'en_ligne' => true,
            'valide_at' => now(),
        ]);

        Sanctum::actingAs($chauffeur->utilisateur);

        $this->postJson('/api/v1/chauffeur', $this->fiche(['vehicule_modele' => 'Toyota Vitz']))
            ->assertOk()
            ->assertJsonPath('statutValidation', Chauffeur::VALIDATION_EN_ATTENTE)
            ->assertJsonPath('enLigne', false);

        $this->assertDatabaseHas('chauffeurs', [
            'id' => $chauffeur->id,
            'vehicule_modele' => 'Toyota Vitz',
            'statut_validation' => Chauffeur::VALIDATION_EN_ATTENTE,
            'valide_at' => null,
        ]);
    }

    public function test_une_plaque_deja_utilisee_est_refusee(): void
    {
        $existant = Chauffeur::factory()->create(['plaque_immatriculation' => 'BZV-111-CG']);

        Sanctum::actingAs($this->chauffeurSansFiche());

        $this->postJson('/api/v1/chauffeur', $this->fiche(['plaque_immatriculation' => 'BZV-111-CG']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('plaque_immatriculation');

        $this->assertSame(1, Chauffeur::where('plaque_immatriculation', 'BZV-111-CG')->count());
        $this->assertNotNull($existant->fresh());
    }

    public function test_un_type_de_vehicule_inconnu_est_refuse(): void
    {
        Sanctum::actingAs($this->chauffeurSansFiche());

        $this->postJson('/api/v1/chauffeur', $this->fiche(['type_vehicule' => 'camion']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('type_vehicule');
    }

    public function test_un_compte_client_ne_peut_pas_deposer_de_fiche(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => User::ROLE_CLIENT]));

        $this->postJson('/api/v1/chauffeur', $this->fiche())->assertForbidden();

        $this->assertDatabaseCount('chauffeurs', 0);
    }

    public function test_le_depot_exige_une_authentification(): void
    {
        $this->postJson('/api/v1/chauffeur', $this->fiche())->assertUnauthorized();
    }
}
