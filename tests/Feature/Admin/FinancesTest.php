<?php

namespace Tests\Feature\Admin;

use App\Models\Abonnement;
use App\Models\Artisan;
use App\Models\Commission;
use App\Models\Plan;
use App\Models\Transaction;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Abonnements et commissions (§5.4.4).
 */
class FinancesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlanSeeder::class);
        Sanctum::actingAs(User::factory()->admin()->create());
    }

    private function transaction(float $montant, string $operateur, ?float $commission = null): Transaction
    {
        $transaction = Transaction::create([
            'user_id' => User::factory()->create()->id,
            'payable_type' => Plan::class,
            'payable_id' => Plan::where('slug', 'pro')->value('id'),
            'montant' => $montant,
            'devise' => 'XAF',
            'operateur' => $operateur,
            'reference_interne' => 'MBK-'.uniqid(),
            'statut' => Transaction::STATUT_REUSSIE,
            'payee_at' => now(),
        ]);

        if ($commission !== null) {
            Commission::create([
                'transaction_id' => $transaction->id,
                'type' => 'abonnement',
                'taux' => 5,
                'montant' => $commission,
            ]);
        }

        return $transaction;
    }

    public function test_la_synthese_du_mois_agrege_par_operateur(): void
    {
        $this->transaction(5000, Transaction::OPERATEUR_MTN, 250);
        $this->transaction(15000, Transaction::OPERATEUR_MTN, 750);
        $this->transaction(5000, Transaction::OPERATEUR_AIRTEL, 250);

        $reponse = $this->getJson('/api/v1/admin/finances')->assertOk();

        $this->assertEqualsWithDelta(25000, $reponse->json('revenus.total'), 0.01);
        $this->assertSame(3, $reponse->json('revenus.nbTransactions'));
        $this->assertEqualsWithDelta(1250, $reponse->json('commissions.total'), 0.01);

        $parOperateur = collect($reponse->json('revenus.parOperateur'))->keyBy('operateur');
        $this->assertSame(2, $parOperateur['mtn']['nombre']);
        $this->assertEqualsWithDelta(20000, $parOperateur['mtn']['total'], 0.01);
    }

    public function test_les_transactions_hors_periode_sont_exclues(): void
    {
        $ancienne = $this->transaction(9000, Transaction::OPERATEUR_MTN);
        $ancienne->update(['payee_at' => now()->subMonths(3)]);

        $this->transaction(5000, Transaction::OPERATEUR_MTN);

        $this->getJson('/api/v1/admin/finances')
            ->assertOk()
            ->assertJsonPath('revenus.nbTransactions', 1);
    }

    public function test_les_echecs_sont_comptes_a_part(): void
    {
        Transaction::create([
            'user_id' => User::factory()->create()->id,
            'montant' => 15000,
            'devise' => 'XAF',
            'operateur' => Transaction::OPERATEUR_AIRTEL,
            'reference_interne' => 'MBK-ECHEC',
            'statut' => Transaction::STATUT_ECHOUEE,
        ]);

        $reponse = $this->getJson('/api/v1/admin/finances')->assertOk();

        $this->assertSame(1, $reponse->json('echecs.nombre'));
        $this->assertEqualsWithDelta(0, $reponse->json('revenus.total'), 0.01);
    }

    public function test_la_repartition_compte_les_artisans_sans_abonnement_au_plan_gratuit(): void
    {
        $abonne = Artisan::factory()->create();
        Artisan::factory()->count(3)->create();

        Abonnement::create([
            'artisan_id' => $abonne->id,
            'plan_id' => Plan::where('slug', 'premium')->value('id'),
            'periodicite' => 'mensuel',
            'debut' => now()->toDateString(),
            'fin' => now()->addMonth()->toDateString(),
            'statut' => Abonnement::STATUT_ACTIF,
        ]);

        $repartition = collect($this->getJson('/api/v1/admin/finances')->json('repartitionAbonnements'))
            ->keyBy('slug');

        // Les trois artisans sans abonnement sont de fait au plan gratuit :
        // les omettre fausserait la lecture des parts.
        $this->assertSame(3, $repartition['gratuit']['nombre']);
        $this->assertSame(1, $repartition['premium']['nombre']);
        $this->assertEqualsWithDelta(75, $repartition['gratuit']['part'], 0.1);
    }

    public function test_l_export_produit_un_csv_lisible_par_un_tableur_francais(): void
    {
        $this->transaction(5000, Transaction::OPERATEUR_MTN, 250);

        $reponse = $this->get('/api/v1/admin/finances/export');

        $reponse->assertOk();
        $reponse->assertHeader('content-type', 'text/csv; charset=UTF-8');

        $contenu = $reponse->streamedContent();

        // BOM UTF-8 : sans lui, Excel massacre les accents.
        $this->assertStringStartsWith("\xEF\xBB\xBF", $contenu);
        // Séparateur point-virgule : la virgule sert de décimale en français.
        // fputcsv encadre les valeurs contenant un espace.
        $this->assertStringContainsString('Référence;"Date de paiement"', $contenu);
        $this->assertStringContainsString('mtn;5000;250;abonnement', $contenu);
    }

    public function test_les_finances_sont_reservees_a_l_administration(): void
    {
        Sanctum::actingAs(User::factory()->artisan()->create());

        $this->getJson('/api/v1/admin/finances')->assertForbidden();
        $this->get('/api/v1/admin/finances/export')->assertForbidden();
    }
}
