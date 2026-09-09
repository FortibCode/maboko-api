<?php

namespace Tests\Feature\Confiance;

use App\Models\Abonnement;
use App\Models\Artisan;
use App\Models\Commission;
use App\Models\Plan;
use App\Models\Transaction;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Les opérateurs Mobile Money réémettent volontiers la même notification.
 * Le traitement doit être idempotent, sinon un abonnement se prolonge à
 * chaque répétition et les commissions se dupliquent.
 */
class WebhookPaiementTest extends TestCase
{
    use RefreshDatabase;

    private Artisan $artisan;

    private Plan $plan;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlanSeeder::class);
        config([
            'paiement.mode' => 'simulation',
            'paiement.commission.abonnement' => 5.0,
        ]);

        $this->artisan = Artisan::factory()->create(['statut_validation' => Artisan::VALIDATION_VALIDE]);
        $this->plan = Plan::where('slug', 'pro')->first();
    }

    private function transactionEnAttente(): Transaction
    {
        return Transaction::create([
            'user_id' => $this->artisan->utilisateur_id,
            'payable_type' => Plan::class,
            'payable_id' => $this->plan->id,
            'montant' => 5000,
            'devise' => 'XAF',
            'operateur' => Transaction::OPERATEUR_MTN,
            'reference_interne' => 'MBK-TEST-0001',
            'reference_externe' => 'EXT-0001',
            'statut' => Transaction::STATUT_EN_ATTENTE,
            'payload' => ['periodicite' => 'mensuel', 'artisan_id' => $this->artisan->id],
        ]);
    }

    private function notifier(array $donnees): TestResponse
    {
        return $this->postJson('/api/v1/webhooks/paiement/mtn', $donnees);
    }

    public function test_une_notification_reussie_active_l_abonnement(): void
    {
        $transaction = $this->transactionEnAttente();

        $this->notifier(['reference' => 'EXT-0001', 'statut' => 'reussie'])
            ->assertOk()
            ->assertJsonPath('message', 'Notification traitée.');

        $this->assertSame(Transaction::STATUT_REUSSIE, $transaction->fresh()->statut);
        $this->assertNotNull($transaction->fresh()->payee_at);
        $this->assertDatabaseHas('abonnements', [
            'artisan_id' => $this->artisan->id,
            'plan_id' => $this->plan->id,
            'statut' => Abonnement::STATUT_ACTIF,
        ]);
    }

    public function test_une_notification_repetee_ne_prolonge_pas_l_abonnement(): void
    {
        $this->transactionEnAttente();

        $this->notifier(['reference' => 'EXT-0001', 'statut' => 'reussie'])->assertOk();
        $finApresPremier = Abonnement::first()->fin;

        // L'opérateur réémet trois fois la même notification.
        for ($i = 0; $i < 3; $i++) {
            $this->notifier(['reference' => 'EXT-0001', 'statut' => 'reussie'])
                ->assertOk()
                ->assertJsonPath('message', 'Notification déjà traitée.');
        }

        $this->assertSame(1, Abonnement::count());
        $this->assertEquals($finApresPremier, Abonnement::first()->fin, "L'abonnement a été prolongé à chaque répétition.");
        $this->assertSame(1, Commission::count(), 'La commission a été enregistrée plusieurs fois.');
    }

    public function test_une_notification_d_echec_n_active_rien(): void
    {
        $transaction = $this->transactionEnAttente();

        $this->notifier(['reference' => 'EXT-0001', 'statut' => 'echouee', 'motif' => 'Solde insuffisant.'])
            ->assertOk();

        $this->assertSame(Transaction::STATUT_ECHOUEE, $transaction->fresh()->statut);
        $this->assertSame('Solde insuffisant.', $transaction->fresh()->motif_echec);
        $this->assertDatabaseCount('abonnements', 0);
    }

    public function test_une_transaction_deja_echouee_ne_peut_pas_etre_repassee_en_reussie(): void
    {
        $transaction = $this->transactionEnAttente();
        $transaction->update(['statut' => Transaction::STATUT_ECHOUEE]);

        $this->notifier(['reference' => 'EXT-0001', 'statut' => 'reussie'])
            ->assertOk()
            ->assertJsonPath('message', 'Notification déjà traitée.');

        $this->assertSame(Transaction::STATUT_ECHOUEE, $transaction->fresh()->statut);
        $this->assertDatabaseCount('abonnements', 0);
    }

    public function test_une_notification_sans_transaction_connue_est_ignoree_sans_erreur(): void
    {
        // Un 404 pousserait l'opérateur à réémettre indéfiniment.
        $this->notifier(['reference' => 'INCONNUE', 'statut' => 'reussie'])
            ->assertOk()
            ->assertJsonPath('message', 'Transaction inconnue, notification ignorée.');
    }

    public function test_une_notification_sans_reference_est_refusee(): void
    {
        $this->notifier(['statut' => 'reussie'])->assertStatus(422);
    }

    public function test_un_operateur_inconnu_est_refuse(): void
    {
        $this->postJson('/api/v1/webhooks/paiement/orange', ['reference' => 'X'])->assertNotFound();
    }

    public function test_en_mode_reel_une_signature_absente_fait_rejeter_la_notification(): void
    {
        config(['paiement.mode' => 'reel', 'paiement.mtn.secret_webhook' => null]);

        $this->transactionEnAttente();

        // Sans secret configuré, rien n'est accepté : mieux vaut refuser un
        // paiement que d'en enregistrer un inventé.
        $this->notifier(['referenceId' => 'EXT-0001', 'status' => 'SUCCESSFUL'])
            ->assertStatus(401);

        $this->assertDatabaseCount('abonnements', 0);
    }

    public function test_en_mode_reel_une_signature_valide_est_acceptee(): void
    {
        config(['paiement.mode' => 'reel', 'paiement.mtn.secret_webhook' => 'secret-de-test']);

        $transaction = $this->transactionEnAttente();

        $corps = ['referenceId' => 'EXT-0001', 'status' => 'SUCCESSFUL', 'financialTransactionId' => 'FT-99'];
        $signature = hash_hmac('sha256', json_encode($corps), 'secret-de-test');

        $this->withHeader('X-Signature', $signature)
            ->postJson('/api/v1/webhooks/paiement/mtn', $corps)
            ->assertOk();

        $this->assertSame(Transaction::STATUT_REUSSIE, $transaction->fresh()->statut);
    }

    public function test_en_mode_reel_une_signature_falsifiee_est_rejetee(): void
    {
        config(['paiement.mode' => 'reel', 'paiement.mtn.secret_webhook' => 'secret-de-test']);

        $transaction = $this->transactionEnAttente();

        $this->withHeader('X-Signature', 'signature-inventee')
            ->postJson('/api/v1/webhooks/paiement/mtn', ['referenceId' => 'EXT-0001', 'status' => 'SUCCESSFUL'])
            ->assertStatus(401);

        $this->assertSame(Transaction::STATUT_EN_ATTENTE, $transaction->fresh()->statut);
    }

    public function test_le_webhook_est_public_mais_ne_fuit_rien(): void
    {
        // Aucune authentification requise, et aucune donnée de transaction
        // renvoyée dans la réponse.
        $reponse = $this->notifier(['reference' => 'INCONNUE'])->assertOk();

        $this->assertSame(['message'], array_keys($reponse->json()));
    }
}
