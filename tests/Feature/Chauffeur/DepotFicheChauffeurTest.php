<?php

namespace Tests\Feature\Chauffeur;

use App\Models\Chauffeur;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
