<?php

namespace Tests\Feature\Confiance;

use App\Models\Abonnement;
use App\Models\Artisan;
use App\Models\Transaction;
use App\Models\User;
use App\Services\ClassementArtisan;
use Database\Seeders\BadgeSeeder;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AbonnementTest extends TestCase
{
    use RefreshDatabase;

    private Artisan $artisan;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([PlanSeeder::class, BadgeSeeder::class]);
        config(['paiement.mode' => 'simulation']);

        $this->artisan = Artisan::factory()->create(['statut_validation' => Artisan::VALIDATION_VALIDE]);
        Sanctum::actingAs($this->artisan->utilisateur);
    }

    private function souscrire(array $remplacements = []): TestResponse
    {
        return $this->postJson('/api/v1/abonnement', array_merge([
            'plan' => 'pro',
            'periodicite' => 'mensuel',
            'operateur' => Transaction::OPERATEUR_MTN,
            'telephone' => '+242061234567',
        ], $remplacements));
    }

    // ------------------------------------------------------------------
    // Catalogue
    // ------------------------------------------------------------------

    public function test_les_quatre_formules_sont_exposees(): void
    {
        $this->getJson('/api/v1/plans')
            ->assertOk()
            ->assertJsonCount(4, 'data')
            ->assertJsonPath('data.0.slug', 'gratuit')
            ->assertJsonStructure(['data' => [['slug', 'nom', 'prixMensuel', 'prixAnnuel', 'avantages', 'boostClassement']]]);
    }

    public function test_sans_souscription_l_artisan_est_au_plan_gratuit(): void
    {
        $this->getJson('/api/v1/abonnement')
            ->assertOk()
            ->assertJsonPath('plan.slug', 'gratuit');
    }

    // ------------------------------------------------------------------
    // Souscription
    // ------------------------------------------------------------------

    public function test_une_souscription_payee_active_l_abonnement(): void
    {
        $this->souscrire()
            ->assertCreated()
            ->assertJsonPath('abonnementActif', true)
            ->assertJsonPath('transaction.statut', Transaction::STATUT_REUSSIE);

        $this->getJson('/api/v1/abonnement')
            ->assertOk()
            ->assertJsonPath('plan.slug', 'pro')
            ->assertJsonPath('finLe', now()->addMonth()->toDateString());
    }

    public function test_un_paiement_refuse_n_active_rien(): void
    {
        // La simulation refuse les numéros terminant par 0.
        $this->souscrire(['telephone' => '+242061234560'])
            ->assertStatus(422)
            ->assertJsonPath('abonnementActif', false)
            ->assertJsonPath('transaction.statut', Transaction::STATUT_ECHOUEE);

        $this->getJson('/api/v1/abonnement')->assertJsonPath('plan.slug', 'gratuit');
        $this->assertDatabaseCount('abonnements', 0);
    }

    public function test_le_plan_gratuit_ne_declenche_aucun_paiement(): void
    {
        $this->souscrire(['plan' => 'gratuit'])
            ->assertCreated()
            ->assertJsonPath('transaction', null);

        $this->assertDatabaseCount('transactions', 0);
        $this->assertDatabaseHas('abonnements', ['artisan_id' => $this->artisan->id]);
    }

    public function test_la_souscription_annuelle_court_sur_douze_mois(): void
    {
        $this->souscrire(['periodicite' => 'annuel'])->assertCreated();

        $this->getJson('/api/v1/abonnement')
            ->assertJsonPath('finLe', now()->addYear()->toDateString());
    }

    public function test_un_renouvellement_prolonge_la_periode_en_cours(): void
    {
        $this->souscrire()->assertCreated();
        $premiereFin = $this->getJson('/api/v1/abonnement')->json('finLe');

        // Renouveler trois jours avant l'échéance ne doit pas les faire perdre.
        $this->souscrire()->assertCreated();

        $this->assertSame(
            now()->addMonths(2)->toDateString(),
            $this->getJson('/api/v1/abonnement')->json('finLe'),
            "Le renouvellement est reparti d'aujourd'hui au lieu de prolonger le {$premiereFin}.",
        );
    }

    public function test_changer_de_formule_desactive_la_precedente(): void
    {
        $this->souscrire(['plan' => 'pro'])->assertCreated();
        $this->souscrire(['plan' => 'premium'])->assertCreated();

        $this->getJson('/api/v1/abonnement')->assertJsonPath('plan.slug', 'premium');

        $this->assertSame(
            1,
            Abonnement::where('artisan_id', $this->artisan->id)
                ->where('statut', Abonnement::STATUT_ACTIF)
                ->count(),
            'Deux formules sont actives en même temps.',
        );
    }

    // ------------------------------------------------------------------
    // Effets
    // ------------------------------------------------------------------

    public function test_la_formule_fait_monter_le_score_de_classement(): void
    {
        $this->artisan->update(['note_moyenne' => 4.5, 'nb_avis' => 20, 'nb_missions_terminees' => 10]);
        app(ClassementArtisan::class)->recalculerScore($this->artisan);
        $avant = (float) $this->artisan->fresh()->score_classement;

        $this->souscrire(['plan' => 'premium'])->assertCreated();

        $this->assertGreaterThan(
            $avant,
            (float) $this->artisan->fresh()->score_classement,
            "La formule n'a pas d'effet sur le classement (§4.5).",
        );
    }

    public function test_une_commission_est_enregistree_si_le_taux_le_prevoit(): void
    {
        config(['paiement.commission.abonnement' => 5.0]);

        $this->souscrire()->assertCreated();

        $transaction = Transaction::first();
        $this->assertDatabaseHas('commissions', [
            'transaction_id' => $transaction->id,
            'type' => 'abonnement',
        ]);
    }

    // ------------------------------------------------------------------
    // Validation et accès
    // ------------------------------------------------------------------

    public function test_un_operateur_inconnu_est_refuse(): void
    {
        $this->souscrire(['operateur' => 'orange'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('operateur');
    }

    public function test_un_numero_hors_format_congolais_est_refuse(): void
    {
        $this->souscrire(['telephone' => '+33612345678'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('telephone');
    }

    public function test_un_compte_sans_fiche_artisan_ne_peut_pas_souscrire(): void
    {
        Sanctum::actingAs(User::factory()->artisan()->create());

        $this->souscrire()->assertStatus(422);
    }

    public function test_les_transactions_sont_privees(): void
    {
        $this->souscrire()->assertCreated();
        $reference = Transaction::first()->reference_interne;

        Sanctum::actingAs(User::factory()->create());

        $this->getJson("/api/v1/transactions/{$reference}")->assertNotFound();
    }

    public function test_le_catalogue_exige_une_authentification(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $this->getJson('/api/v1/plans')->assertOk();

        app('auth')->forgetGuards();
        $this->getJson('/api/v1/plans')->assertStatus(401);
    }
}
