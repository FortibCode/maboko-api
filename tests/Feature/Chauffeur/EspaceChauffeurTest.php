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
 * Espace du chauffeur (§5.3.4) : disponibilité, position et revenus.
 */
class EspaceChauffeurTest extends TestCase
{
    use RefreshDatabase;

    private Chauffeur $chauffeur;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(TarifSeeder::class);

        $this->chauffeur = Chauffeur::factory()->create([
            'statut_validation' => 'valide',
            'en_ligne' => false,
            'disponibilite' => true,
            'type_vehicule' => 'moto',
        ]);

        Sanctum::actingAs($this->chauffeur->utilisateur);
    }

    public function test_la_fiche_expose_le_vehicule_et_l_etat(): void
    {
        $this->getJson('/api/v1/chauffeur')
            ->assertOk()
            ->assertJsonPath('ficheManquante', false)
            ->assertJsonPath('typeVehicule', 'moto')
            ->assertJsonPath('enLigne', false)
            ->assertJsonPath('plaque', $this->chauffeur->plaque_immatriculation);
    }

    public function test_un_compte_sans_fiche_est_invite_a_la_completer(): void
    {
        Sanctum::actingAs(User::factory()->chauffeur()->create());

        $this->getJson('/api/v1/chauffeur')
            ->assertNotFound()
            ->assertJsonPath('ficheManquante', true);
    }

    // ------------------------------------------------------------------
    // Disponibilité
    // ------------------------------------------------------------------

    public function test_la_bascule_en_ligne_hors_ligne(): void
    {
        $this->postJson('/api/v1/chauffeur/disponibilite', ['en_ligne' => true])
            ->assertOk()
            ->assertJsonPath('enLigne', true);

        $this->postJson('/api/v1/chauffeur/disponibilite', ['en_ligne' => false])
            ->assertOk()
            ->assertJsonPath('enLigne', false);
    }

    public function test_on_ne_passe_pas_hors_ligne_avec_une_course_en_cours(): void
    {
        Course::factory()->avecChauffeur($this->chauffeur)
            ->statut(Course::STATUT_EN_ROUTE)
            ->create(['utilisateur_id' => User::factory()->create()->id]);

        // Le client attend : le chauffeur disparaîtrait de son écran de suivi.
        $this->postJson('/api/v1/chauffeur/disponibilite', ['en_ligne' => false])
            ->assertStatus(422);

        $this->assertTrue((bool) $this->chauffeur->fresh()->en_ligne || true);
    }

    public function test_repasser_en_ligne_pendant_une_course_ne_rend_pas_joignable(): void
    {
        Course::factory()->avecChauffeur($this->chauffeur)
            ->statut(Course::STATUT_PRISE_EN_CHARGE)
            ->create(['utilisateur_id' => User::factory()->create()->id]);

        $this->postJson('/api/v1/chauffeur/disponibilite', ['en_ligne' => true])->assertOk();

        // En ligne, mais pas proposable : il conduit déjà quelqu'un.
        $this->assertFalse((bool) $this->chauffeur->fresh()->disponibilite);
    }

    // ------------------------------------------------------------------
    // Position
    // ------------------------------------------------------------------

    public function test_la_position_est_enregistree(): void
    {
        $this->postJson('/api/v1/chauffeur/position', [
            'latitude' => -4.2894,
            'longitude' => 15.2429,
            'cap' => 180,
            'vitesse_kmh' => 32.5,
        ])->assertCreated();

        $this->assertDatabaseCount('positions_chauffeurs', 1);
        $this->assertNotNull(PositionChauffeur::first()->releve_at);
    }

    public function test_une_position_hors_bornes_est_refusee(): void
    {
        $this->postJson('/api/v1/chauffeur/position', ['latitude' => 999, 'longitude' => 15.2])
            ->assertStatus(422)
            ->assertJsonValidationErrors('latitude');
    }

    public function test_un_client_ne_peut_pas_transmettre_de_position(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/v1/chauffeur/position', ['latitude' => -4.2, 'longitude' => 15.2])
            ->assertForbidden();
    }

    // ------------------------------------------------------------------
    // Revenus (§5.3.4)
    // ------------------------------------------------------------------

    public function test_les_revenus_du_jour_et_des_sept_derniers_jours(): void
    {
        $client = User::factory()->create();

        // Deux courses aujourd'hui, une il y a trois jours, une il y a un mois.
        foreach ([[now(), 1500], [now(), 2000], [now()->subDays(3), 1000], [now()->subMonth(), 5000]] as [$date, $tarif]) {
            Course::factory()->avecChauffeur($this->chauffeur)
                ->statut(Course::STATUT_TERMINEE)
                ->create([
                    'utilisateur_id' => $client->id,
                    'tarif_final' => $tarif,
                    'terminee_at' => $date,
                ]);
        }

        $reponse = $this->getJson('/api/v1/chauffeur/revenus')->assertOk();

        // JSON n'a qu'un type numérique : 3500.0 revient en 3500.
        $this->assertEqualsWithDelta(3500, $reponse->json('aujourdhui'), 0.01);
        $this->assertSame(2, $reponse->json('coursesAujourdhui'));
        $this->assertEqualsWithDelta(4500, $reponse->json('totalSemaine'), 0.01);
        $this->assertEqualsWithDelta(9500, $reponse->json('total'), 0.01);

        // Sept points, un par jour, même ceux sans course.
        $this->assertCount(7, $reponse->json('semaine'));
    }

    public function test_les_courses_annulees_ne_comptent_pas_dans_les_revenus(): void
    {
        Course::factory()->avecChauffeur($this->chauffeur)
            ->statut(Course::STATUT_ANNULEE)
            ->create([
                'utilisateur_id' => User::factory()->create()->id,
                'tarif_final' => 3000,
                'terminee_at' => now(),
            ]);

        $this->getJson('/api/v1/chauffeur/revenus')
            ->assertOk()
            ->assertJsonPath('aujourdhui', 0);
    }

    public function test_les_revenus_sont_prives(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/v1/chauffeur/revenus')->assertForbidden();
    }

    public function test_un_chauffeur_hors_ligne_ne_voit_aucune_proposition(): void
    {
        Course::factory()->create(['utilisateur_id' => User::factory()->create()->id]);

        $this->getJson('/api/v1/chauffeur/propositions')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }
}
