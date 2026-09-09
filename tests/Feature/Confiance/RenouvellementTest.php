<?php

namespace Tests\Feature\Confiance;

use App\Models\Abonnement;
use App\Models\Artisan;
use App\Models\Notification;
use App\Models\Plan;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RenouvellementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlanSeeder::class);
        config(['paiement.mode' => 'simulation']);
    }

    private function abonnement(string $fin, array $remplacements = []): Abonnement
    {
        // Numéro explicite : la passerelle de simulation refuse ceux qui se
        // terminent par 0, et une fabrique aléatoire rendrait le test instable.
        $artisan = Artisan::factory()
            ->for(User::factory()->artisan()->state(['telephone' => '+242061234567']), 'utilisateur')
            ->create(['statut_validation' => Artisan::VALIDATION_VALIDE]);

        return Abonnement::create(array_merge([
            'artisan_id' => $artisan->id,
            'plan_id' => Plan::where('slug', 'pro')->value('id'),
            'periodicite' => 'mensuel',
            'debut' => now()->subMonth()->toDateString(),
            'fin' => $fin,
            'statut' => Abonnement::STATUT_ACTIF,
            'renouvellement_auto' => true,
        ], $remplacements));
    }

    public function test_une_relance_part_trois_jours_avant_l_echeance(): void
    {
        $abonnement = $this->abonnement(now()->addDays(3)->toDateString());

        $this->artisan('maboko:renouveler-abonnements')->assertSuccessful();

        $this->assertDatabaseHas('notifications', [
            'artisan_id' => $abonnement->artisan->utilisateur_id,
            'type' => 'abonnement_echeance',
        ]);
        $this->assertNotNull($abonnement->fresh()->derniere_relance_at);
    }

    public function test_la_relance_ne_part_qu_une_fois(): void
    {
        $this->abonnement(now()->addDays(3)->toDateString());

        $this->artisan('maboko:renouveler-abonnements')->assertSuccessful();
        $this->artisan('maboko:renouveler-abonnements')->assertSuccessful();

        $this->assertSame(1, Notification::where('type', 'abonnement_echeance')->count());
    }

    public function test_un_abonnement_echu_est_preleve_et_prolonge(): void
    {
        $abonnement = $this->abonnement(now()->toDateString());

        $this->artisan('maboko:renouveler-abonnements')->assertSuccessful();

        $this->assertTrue(
            $abonnement->fresh()->fin->isAfter(now()->addDays(20)),
            "L'abonnement échu n'a pas été prolongé.",
        );
        $this->assertDatabaseCount('transactions', 1);
    }

    public function test_un_abonnement_sans_renouvellement_auto_n_est_pas_preleve(): void
    {
        $this->abonnement(now()->toDateString(), ['renouvellement_auto' => false]);

        $this->artisan('maboko:renouveler-abonnements')->assertSuccessful();

        $this->assertDatabaseCount('transactions', 0);
    }

    public function test_un_abonnement_impaye_est_clos_apres_trois_jours(): void
    {
        $abonnement = $this->abonnement(now()->subDays(5)->toDateString(), ['renouvellement_auto' => false]);

        $this->artisan('maboko:renouveler-abonnements')->assertSuccessful();

        $this->assertSame(Abonnement::STATUT_EXPIRE, $abonnement->fresh()->statut);
    }

    public function test_un_delai_de_grace_protege_les_paiements_en_cours(): void
    {
        // Expiré d'un jour seulement : le paiement Mobile Money peut encore aboutir.
        $abonnement = $this->abonnement(now()->subDay()->toDateString(), ['renouvellement_auto' => false]);

        $this->artisan('maboko:renouveler-abonnements')->assertSuccessful();

        $this->assertSame(Abonnement::STATUT_ACTIF, $abonnement->fresh()->statut);
    }

    public function test_le_mode_simulation_n_ecrit_rien(): void
    {
        $abonnement = $this->abonnement(now()->toDateString());

        $this->artisan('maboko:renouveler-abonnements', ['--simulation' => true])->assertSuccessful();

        $this->assertDatabaseCount('transactions', 0);
        $this->assertDatabaseCount('notifications', 0);
        $this->assertNull($abonnement->fresh()->derniere_relance_at);
    }
}
