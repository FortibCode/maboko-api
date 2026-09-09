<?php

namespace Tests\Feature\Chauffeur;

use App\Models\Chauffeur;
use App\Models\Course;
use App\Models\PositionChauffeur;
use App\Models\User;
use Database\Seeders\TarifSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CourseTest extends TestCase
{
    use RefreshDatabase;

    private User $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(TarifSeeder::class);

        $this->client = User::factory()->create(['role' => User::ROLE_CLIENT]);
    }

    /** Chauffeur en ligne, validé, positionné près du départ. */
    private function chauffeurProche(string $type = 'moto', float $lat = -4.2890, float $lng = 15.2425): Chauffeur
    {
        $chauffeur = Chauffeur::factory()->create([
            'type_vehicule' => $type,
            'statut_validation' => 'valide',
            'en_ligne' => true,
            'disponibilite' => true,
        ]);

        PositionChauffeur::create([
            'chauffeur_id' => $chauffeur->id,
            'latitude' => $lat,
            'longitude' => $lng,
            'releve_at' => now(),
        ]);

        return $chauffeur;
    }

    private function reserver(array $remplacements = []): TestResponse
    {
        return $this->postJson('/api/v1/courses', array_merge([
            'lieu_depart' => 'Bacongo, Brazzaville',
            'lieu_arrivee' => 'Mpila, Brazzaville',
            'depart_latitude' => -4.2894,
            'depart_longitude' => 15.2429,
            'arrivee_latitude' => -4.2610,
            'arrivee_longitude' => 15.2900,
            'type_vehicule' => 'moto',
        ], $remplacements));
    }

    // ------------------------------------------------------------------
    // Parcours nominal
    // ------------------------------------------------------------------

    public function test_le_parcours_complet_de_la_reservation_a_la_depose(): void
    {
        $chauffeur = $this->chauffeurProche();

        // 1. Le client réserve
        Sanctum::actingAs($this->client);
        $reponse = $this->reserver()->assertCreated();

        $id = $reponse->json('course.id');
        $this->assertSame(1, $reponse->json('chauffeursContactes'));
        $this->assertSame(Course::STATUT_RECHERCHE, $reponse->json('course.statut'));
        $this->assertGreaterThan(0, $reponse->json('course.tarifEstime'));

        // 2. Le chauffeur voit la proposition et l'accepte
        Sanctum::actingAs($chauffeur->utilisateur);
        $this->getJson('/api/v1/chauffeur/propositions')
            ->assertOk()
            ->assertJsonPath('data.0.id', $id);

        $this->postJson("/api/v1/courses/{$id}/accepter")
            ->assertOk()
            ->assertJsonPath('course.statut', Course::STATUT_ACCEPTEE);

        // Il n'est plus proposable tant qu'il n'a pas déposé.
        $this->assertFalse((bool) $chauffeur->fresh()->disponibilite);

        // 3. En route, prise en charge, dépose
        $this->postJson("/api/v1/courses/{$id}/demarrer")
            ->assertOk()->assertJsonPath('course.statut', Course::STATUT_EN_ROUTE);
        $this->postJson("/api/v1/courses/{$id}/prise-en-charge")
            ->assertOk()->assertJsonPath('course.statut', Course::STATUT_PRISE_EN_CHARGE);
        $this->postJson("/api/v1/courses/{$id}/terminer", ['tarif_final' => 1500])
            ->assertOk()->assertJsonPath('course.statut', Course::STATUT_TERMINEE);

        // 4. Le chauffeur redevient disponible et son compteur monte
        $chauffeur->refresh();
        $this->assertTrue((bool) $chauffeur->disponibilite);
        $this->assertSame(1, $chauffeur->nb_courses_terminees);
    }

    public function test_le_client_voit_le_vehicule_et_la_plaque_apres_acceptation(): void
    {
        $chauffeur = $this->chauffeurProche();

        Sanctum::actingAs($this->client);
        $id = $this->reserver()->json('course.id');

        Sanctum::actingAs($chauffeur->utilisateur);
        $this->postJson("/api/v1/courses/{$id}/accepter")->assertOk();

        // Le client doit pouvoir reconnaître le véhicule qui arrive.
        Sanctum::actingAs($this->client);
        $this->getJson("/api/v1/courses/{$id}")
            ->assertOk()
            ->assertJsonPath('data.chauffeur.plaque', $chauffeur->plaque_immatriculation)
            ->assertJsonPath('data.chauffeur.vehicule', $chauffeur->vehicule_modele);
    }

    // ------------------------------------------------------------------
    // Appariement
    // ------------------------------------------------------------------

    public function test_un_chauffeur_trop_loin_n_est_pas_contacte(): void
    {
        // Pointe-Noire, à plus de 350 km.
        $this->chauffeurProche('moto', -4.7889, 11.8653);

        Sanctum::actingAs($this->client);

        $this->reserver()->assertCreated()->assertJsonPath('chauffeursContactes', 0);
    }

    public function test_un_chauffeur_hors_ligne_n_est_pas_contacte(): void
    {
        $chauffeur = $this->chauffeurProche();
        $chauffeur->update(['en_ligne' => false]);

        Sanctum::actingAs($this->client);

        $this->reserver()->assertJsonPath('chauffeursContactes', 0);
    }

    public function test_une_position_trop_ancienne_ecarte_le_chauffeur(): void
    {
        $chauffeur = $this->chauffeurProche();
        // Le chauffeur a pu éteindre son téléphone sans se déclarer hors ligne.
        PositionChauffeur::where('chauffeur_id', $chauffeur->id)
            ->update(['releve_at' => now()->subMinutes(20)]);

        Sanctum::actingAs($this->client);

        $this->reserver()->assertJsonPath('chauffeursContactes', 0);
    }

    public function test_le_type_de_vehicule_demande_est_respecte(): void
    {
        $this->chauffeurProche('voiture');

        Sanctum::actingAs($this->client);

        $this->reserver(['type_vehicule' => 'moto'])->assertJsonPath('chauffeursContactes', 0);
        $this->assertTrue(true);
    }

    public function test_le_premier_chauffeur_a_accepter_emporte_la_course(): void
    {
        $premier = $this->chauffeurProche();
        $second = $this->chauffeurProche();

        Sanctum::actingAs($this->client);
        $id = $this->reserver()->json('course.id');

        Sanctum::actingAs($premier->utilisateur);
        $this->postJson("/api/v1/courses/{$id}/accepter")->assertOk();

        // Le second arrive trop tard : cas courant en heure de pointe.
        Sanctum::actingAs($second->utilisateur);
        $this->postJson("/api/v1/courses/{$id}/accepter")
            ->assertStatus(409)
            ->assertJsonPath('message', 'Cette course vient d’être prise par un autre chauffeur.');

        $this->assertSame($premier->id, Course::find($id)->chauffeur_id);
        $this->assertTrue((bool) $second->fresh()->disponibilite);
    }

    public function test_un_chauffeur_ne_peut_pas_accepter_une_course_d_un_autre_type(): void
    {
        $this->chauffeurProche('moto');
        $voiture = $this->chauffeurProche('voiture');

        Sanctum::actingAs($this->client);
        $id = $this->reserver(['type_vehicule' => 'moto'])->json('course.id');

        Sanctum::actingAs($voiture->utilisateur);
        $this->postJson("/api/v1/courses/{$id}/accepter")->assertStatus(422);
    }

    // ------------------------------------------------------------------
    // Règles et autorisations
    // ------------------------------------------------------------------

    public function test_une_seule_course_a_la_fois(): void
    {
        $this->chauffeurProche();
        Sanctum::actingAs($this->client);

        $this->reserver()->assertCreated();
        $this->reserver()->assertStatus(422);
    }

    public function test_un_tiers_ne_peut_pas_consulter_une_course(): void
    {
        $course = Course::factory()->create(['utilisateur_id' => $this->client->id]);

        Sanctum::actingAs(User::factory()->create());

        $this->getJson("/api/v1/courses/{$course->id}")->assertForbidden();
    }

    public function test_seul_le_chauffeur_attribue_fait_avancer_la_course(): void
    {
        $chauffeur = $this->chauffeurProche();
        $intrus = $this->chauffeurProche();

        $course = Course::factory()->avecChauffeur($chauffeur)
            ->statut(Course::STATUT_ACCEPTEE)
            ->create(['utilisateur_id' => $this->client->id]);

        Sanctum::actingAs($intrus->utilisateur);
        $this->postJson("/api/v1/courses/{$course->id}/demarrer")->assertForbidden();

        Sanctum::actingAs($this->client);
        $this->postJson("/api/v1/courses/{$course->id}/demarrer")->assertForbidden();
    }

    public function test_on_ne_termine_pas_une_course_sans_client_a_bord(): void
    {
        $chauffeur = $this->chauffeurProche();
        $course = Course::factory()->avecChauffeur($chauffeur)
            ->statut(Course::STATUT_EN_ROUTE)
            ->create(['utilisateur_id' => $this->client->id]);

        Sanctum::actingAs($chauffeur->utilisateur);

        $this->postJson("/api/v1/courses/{$course->id}/terminer")->assertStatus(422);
    }

    public function test_le_client_annule_tant_qu_il_n_est_pas_a_bord(): void
    {
        $chauffeur = $this->chauffeurProche();
        $course = Course::factory()->avecChauffeur($chauffeur)
            ->statut(Course::STATUT_EN_ROUTE)
            ->create(['utilisateur_id' => $this->client->id]);

        Sanctum::actingAs($this->client);
        $this->postJson("/api/v1/courses/{$course->id}/annuler", ['motif' => 'Plus besoin'])
            ->assertOk()
            ->assertJsonPath('course.statut', Course::STATUT_ANNULEE)
            ->assertJsonPath('course.annuleePar', 'client');

        // Le chauffeur redevient joignable.
        $this->assertTrue((bool) $chauffeur->fresh()->disponibilite);
    }

    public function test_une_course_avec_client_a_bord_ne_s_annule_plus(): void
    {
        $chauffeur = $this->chauffeurProche();
        $course = Course::factory()->avecChauffeur($chauffeur)
            ->statut(Course::STATUT_PRISE_EN_CHARGE)
            ->create(['utilisateur_id' => $this->client->id]);

        Sanctum::actingAs($this->client);
        $this->postJson("/api/v1/courses/{$course->id}/annuler")->assertForbidden();

        Sanctum::actingAs($chauffeur->utilisateur);
        $this->postJson("/api/v1/courses/{$course->id}/annuler")->assertForbidden();
    }

    public function test_la_position_du_chauffeur_n_est_plus_exposee_apres_la_depose(): void
    {
        $chauffeur = $this->chauffeurProche();
        $course = Course::factory()->avecChauffeur($chauffeur)
            ->statut(Course::STATUT_TERMINEE)
            ->create(['utilisateur_id' => $this->client->id]);

        Sanctum::actingAs($this->client);

        // Après la course, la position du chauffeur ne regarde plus le client.
        $this->getJson("/api/v1/courses/{$course->id}")
            ->assertOk()
            ->assertJsonPath('data.chauffeur.position', null);
    }

    public function test_des_coordonnees_hors_bornes_sont_refusees(): void
    {
        Sanctum::actingAs($this->client);

        $this->reserver(['depart_latitude' => 200])
            ->assertStatus(422)
            ->assertJsonValidationErrors('depart_latitude');
    }
}
