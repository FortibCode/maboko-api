<?php

namespace Tests\Feature\Admin;

use App\Models\Artisan;
use App\Models\Chauffeur;
use App\Models\Course;
use App\Models\DemandeDevis;
use App\Models\Plan;
use App\Models\Transaction;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TableauDeBordAdminTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlanSeeder::class);
        Cache::flush();
    }

    public function test_les_indicateurs_cles_du_paragraphe_4_4(): void
    {
        $client = User::factory()->create();
        $artisan = Artisan::factory()->create(['statut_validation' => Artisan::VALIDATION_VALIDE]);
        $artisan->utilisateur->update(['derniere_connexion_at' => now()]);

        DemandeDevis::factory()->count(3)->create([
            'client_id' => $client->id, 'artisan_id' => $artisan->id,
        ]);
        DemandeDevis::factory()->statut(DemandeDevis::STATUT_TERMINEE)->create([
            'client_id' => $client->id, 'artisan_id' => $artisan->id,
        ]);

        Transaction::create([
            'user_id' => $artisan->utilisateur_id,
            'payable_type' => Plan::class,
            'payable_id' => Plan::where('slug', 'pro')->value('id'),
            'montant' => 5000,
            'devise' => 'XAF',
            'operateur' => Transaction::OPERATEUR_MTN,
            'reference_interne' => 'MBK-1',
            'statut' => Transaction::STATUT_REUSSIE,
            'payee_at' => now(),
        ]);

        Sanctum::actingAs(User::factory()->admin()->create());

        $this->getJson('/api/v1/admin/tableau-de-bord')
            ->assertOk()
            // Les terminées ne comptent pas parmi les missions actives.
            ->assertJsonPath('indicateurs.missionsActives', 3)
            ->assertJsonPath('indicateurs.artisansActifs', 1)
            ->assertJsonPath('indicateurs.revenusMois', 5000)
            ->assertJsonStructure([
                'indicateurs' => ['utilisateurs', 'chauffeursEnLigne', 'commissionsMois', 'abonnementsActifs'],
                'activite',
                'aTraiter' => ['signalements', 'artisansAValider', 'identitesAVerifier', 'litigesOuverts'],
            ]);
    }

    public function test_le_graphique_couvre_sept_ou_trente_jours(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->getJson('/api/v1/admin/tableau-de-bord')->assertJsonCount(7, 'activite');
        $this->getJson('/api/v1/admin/tableau-de-bord?jours=30')->assertJsonCount(30, 'activite');

        // Une valeur fantaisiste retombe sur sept jours plutôt que d'échouer.
        $this->getJson('/api/v1/admin/tableau-de-bord?jours=999')->assertJsonCount(7, 'activite');
    }

    public function test_les_courses_actives_sont_comptees(): void
    {
        $chauffeur = Chauffeur::factory()->create();
        Course::factory()->avecChauffeur($chauffeur)->statut(Course::STATUT_EN_ROUTE)
            ->create(['utilisateur_id' => User::factory()->create()->id]);
        Course::factory()->statut(Course::STATUT_TERMINEE)
            ->create(['utilisateur_id' => User::factory()->create()->id]);

        Sanctum::actingAs(User::factory()->admin()->create());

        $this->getJson('/api/v1/admin/tableau-de-bord')
            ->assertJsonPath('indicateurs.coursesActives', 1);
    }

    // ------------------------------------------------------------------
    // Accès
    // ------------------------------------------------------------------

    public function test_un_client_n_accede_pas_au_back_office(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => User::ROLE_CLIENT]));

        $this->getJson('/api/v1/admin/tableau-de-bord')->assertForbidden();
    }

    public function test_un_artisan_n_accede_pas_au_back_office(): void
    {
        Sanctum::actingAs(User::factory()->artisan()->create());

        $this->getJson('/api/v1/admin/artisans')->assertForbidden();
        $this->getJson('/api/v1/admin/finances')->assertForbidden();
    }

    public function test_le_back_office_exige_une_authentification(): void
    {
        $this->getJson('/api/v1/admin/tableau-de-bord')->assertStatus(401);
    }
}
