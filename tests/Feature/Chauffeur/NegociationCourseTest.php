<?php

namespace Tests\Feature\Chauffeur;

use App\Models\Chauffeur;
use App\Models\Course;
use App\Models\PositionChauffeur;
use App\Models\User;
use Database\Seeders\TarifSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Négociation du prix d'une course (§5.1.9) : le client propose un prix,
 * un chauffeur peut en proposer un autre, le client accepte ou refuse.
 */
class NegociationCourseTest extends TestCase
{
    use RefreshDatabase;

    private User $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(TarifSeeder::class);

        $this->client = User::factory()->create(['role' => User::ROLE_CLIENT]);
    }

    private function chauffeurProche(): Chauffeur
    {
        $chauffeur = Chauffeur::factory()->create([
            'type_vehicule' => 'moto',
            'statut_validation' => 'valide',
            'en_ligne' => true,
            'disponibilite' => true,
        ]);

        PositionChauffeur::create([
            'chauffeur_id' => $chauffeur->id,
            'latitude' => -4.2890,
            'longitude' => 15.2425,
            'releve_at' => now(),
        ]);

        return $chauffeur;
    }

    private function reserver(?float $prixClient = 1500): int
    {
        Sanctum::actingAs($this->client);

        return $this->postJson('/api/v1/courses', array_filter([
            'lieu_depart' => 'Bacongo, Brazzaville',
            'lieu_arrivee' => 'Mpila, Brazzaville',
            'depart_latitude' => -4.2894,
            'depart_longitude' => 15.2429,
            'arrivee_latitude' => -4.2610,
            'arrivee_longitude' => 15.2900,
            'type_vehicule' => 'moto',
            'prix_client' => $prixClient,
        ], fn ($v) => $v !== null))->assertCreated()->json('course.id');
    }

    private function contreProposer(Chauffeur $chauffeur, int $id, float $prix = 2000)
    {
        Sanctum::actingAs($chauffeur->utilisateur);

        return $this->postJson("/api/v1/courses/{$id}/contre-proposition", ['prix_chauffeur' => $prix]);
    }

    public function test_le_prix_propose_par_le_client_est_enregistre(): void
    {
        Sanctum::actingAs($this->client);
        $id = $this->reserver(1500);

        $this->getJson("/api/v1/courses/{$id}")->assertJsonPath('data.prixClient', 1500);
    }

    public function test_un_prix_client_derisoire_est_refuse(): void
    {
        Sanctum::actingAs($this->client);

        $this->postJson('/api/v1/courses', [
            'lieu_depart' => 'A', 'lieu_arrivee' => 'B',
            'depart_latitude' => -4.2894, 'depart_longitude' => 15.2429,
            'arrivee_latitude' => -4.2610, 'arrivee_longitude' => 15.2900,
            'type_vehicule' => 'moto', 'prix_client' => 10,
        ])->assertUnprocessable()->assertJsonValidationErrors('prix_client');
    }

    public function test_le_parcours_complet_de_negociation(): void
    {
        $chauffeur = $this->chauffeurProche();
        $id = $this->reserver(1500);

        // Le chauffeur contre-propose
        $this->contreProposer($chauffeur, $id, 2000)
            ->assertOk()
            ->assertJsonPath('course.statut', Course::STATUT_NEGOCIATION)
            ->assertJsonPath('course.prixChauffeur', 2000);

        // Il peut suivre la réponse du client
        $this->getJson("/api/v1/courses/{$id}")->assertOk();

        // Le client accepte : la course est attribuée à ce chauffeur
        Sanctum::actingAs($this->client);
        $this->postJson("/api/v1/courses/{$id}/accepter-prix")
            ->assertOk()
            ->assertJsonPath('course.statut', Course::STATUT_ACCEPTEE)
            ->assertJsonPath('course.chauffeur.id', $chauffeur->id);

        $this->assertFalse($chauffeur->fresh()->disponibilite);

        // Le prix convenu devient le tarif final
        Sanctum::actingAs($chauffeur->utilisateur);
        $this->postJson("/api/v1/courses/{$id}/prise-en-charge")->assertOk();
        $this->postJson("/api/v1/courses/{$id}/terminer")
            ->assertOk()
            ->assertJsonPath('course.tarifFinal', 2000);
    }

    public function test_sans_contre_proposition_le_prix_du_client_fait_foi(): void
    {
        $chauffeur = $this->chauffeurProche();
        $id = $this->reserver(1700);

        Sanctum::actingAs($chauffeur->utilisateur);
        $this->postJson("/api/v1/courses/{$id}/accepter")->assertOk();
        $this->postJson("/api/v1/courses/{$id}/prise-en-charge")->assertOk();
        $this->postJson("/api/v1/courses/{$id}/terminer")->assertJsonPath('course.tarifFinal', 1700);
    }

    public function test_une_course_en_negociation_sort_des_propositions_des_autres(): void
    {
        $premier = $this->chauffeurProche();
        $second = $this->chauffeurProche();
        $id = $this->reserver();

        $this->contreProposer($premier, $id)->assertOk();

        Sanctum::actingAs($second->utilisateur);
        $this->getJson('/api/v1/chauffeur/propositions')->assertJsonCount(0, 'data');
        $this->postJson("/api/v1/courses/{$id}/accepter")->assertStatus(409);
        $this->contreProposer($second, $id)->assertStatus(409);
    }

    public function test_le_refus_du_client_rouvre_la_course_aux_autres_seulement(): void
    {
        $premier = $this->chauffeurProche();
        $second = $this->chauffeurProche();
        $id = $this->reserver();

        $this->contreProposer($premier, $id)->assertOk();

        Sanctum::actingAs($this->client);
        $this->postJson("/api/v1/courses/{$id}/refuser-prix")
            ->assertOk()
            ->assertJsonPath('course.statut', Course::STATUT_RECHERCHE)
            ->assertJsonPath('course.prixChauffeur', null);

        Sanctum::actingAs($premier->utilisateur);
        $this->getJson('/api/v1/chauffeur/propositions')->assertJsonCount(0, 'data');

        Sanctum::actingAs($second->utilisateur);
        $this->getJson('/api/v1/chauffeur/propositions')->assertJsonCount(1, 'data');
    }

    public function test_si_le_chauffeur_est_parti_la_course_repart_en_recherche(): void
    {
        $chauffeur = $this->chauffeurProche();
        $id = $this->reserver();

        $this->contreProposer($chauffeur, $id)->assertOk();
        $chauffeur->update(['en_ligne' => false]);

        Sanctum::actingAs($this->client);
        $this->postJson("/api/v1/courses/{$id}/accepter-prix")
            ->assertStatus(409)
            ->assertJsonPath('course.statut', Course::STATUT_RECHERCHE);
    }

    public function test_seul_le_client_repond_a_la_proposition(): void
    {
        $chauffeur = $this->chauffeurProche();
        $id = $this->reserver();
        $this->contreProposer($chauffeur, $id)->assertOk();

        Sanctum::actingAs($chauffeur->utilisateur);
        $this->postJson("/api/v1/courses/{$id}/accepter-prix")->assertForbidden();

        Sanctum::actingAs(User::factory()->create(['role' => User::ROLE_CLIENT]));
        $this->postJson("/api/v1/courses/{$id}/refuser-prix")->assertForbidden();
    }

    public function test_un_client_ne_peut_pas_contre_proposer(): void
    {
        $id = $this->reserver();

        Sanctum::actingAs($this->client);
        $this->postJson("/api/v1/courses/{$id}/contre-proposition", ['prix_chauffeur' => 2000])
            ->assertForbidden();
    }

    public function test_pas_de_seconde_reservation_pendant_une_negociation(): void
    {
        $chauffeur = $this->chauffeurProche();
        $id = $this->reserver();
        $this->contreProposer($chauffeur, $id)->assertOk();

        Sanctum::actingAs($this->client);
        $this->postJson('/api/v1/courses', [
            'lieu_depart' => 'A', 'lieu_arrivee' => 'B',
            'depart_latitude' => -4.2894, 'depart_longitude' => 15.2429,
            'arrivee_latitude' => -4.2610, 'arrivee_longitude' => 15.2900,
            'type_vehicule' => 'moto',
        ])->assertUnprocessable();
    }
}
