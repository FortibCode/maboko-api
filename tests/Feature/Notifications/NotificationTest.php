<?php

namespace Tests\Feature\Notifications;

use App\Models\Appareil;
use App\Models\Artisan;
use App\Models\DemandeDevis;
use App\Models\Notification;
use App\Models\User;
use App\Services\Push\EnvoyeurPush;
use App\Services\ServiceNotification;
use App\Services\Sms\SmsSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

class NotificationTest extends TestCase
{
    use RefreshDatabase;

    private function pushQuiEchoue(): void
    {
        $this->instance(EnvoyeurPush::class, Mockery::mock(EnvoyeurPush::class, function ($mock) {
            $mock->shouldReceive('envoyer')->andReturn(['envoyes' => 0, 'jetons_invalides' => []]);
        }));
    }

    private function pushQuiReussit(): void
    {
        $this->instance(EnvoyeurPush::class, Mockery::mock(EnvoyeurPush::class, function ($mock) {
            $mock->shouldReceive('envoyer')->andReturn(['envoyes' => 1, 'jetons_invalides' => []]);
        }));
    }

    // ------------------------------------------------------------------
    // Enregistrement des appareils
    // ------------------------------------------------------------------

    public function test_un_appareil_s_enregistre_pour_le_push(): void
    {
        Sanctum::actingAs($utilisateur = User::factory()->create());

        $this->postJson('/api/v1/appareils', [
            'jeton_push' => 'jeton-firebase-abc',
            'plateforme' => 'android',
            'modele' => 'Tecno Spark',
        ])->assertCreated();

        $this->assertDatabaseHas('appareils', [
            'user_id' => $utilisateur->id,
            'jeton_push' => 'jeton-firebase-abc',
        ]);
    }

    public function test_un_jeton_change_de_proprietaire_sans_doublon(): void
    {
        $premier = User::factory()->create();
        $second = User::factory()->create();

        Sanctum::actingAs($premier);
        $this->postJson('/api/v1/appareils', ['jeton_push' => 'jeton-partage', 'plateforme' => 'android']);

        // Même téléphone, autre compte : le jeton suit le dernier connecté.
        Sanctum::actingAs($second);
        $this->postJson('/api/v1/appareils', ['jeton_push' => 'jeton-partage', 'plateforme' => 'android'])
            ->assertOk();

        $this->assertSame(1, Appareil::count());
        $this->assertSame($second->id, Appareil::first()->user_id);
    }

    public function test_une_plateforme_inconnue_est_refusee(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/v1/appareils', ['jeton_push' => 'x', 'plateforme' => 'symbian'])
            ->assertStatus(422);
    }

    // ------------------------------------------------------------------
    // Envoi et repli SMS (§7.2)
    // ------------------------------------------------------------------

    public function test_le_push_part_vers_les_appareils_enregistres(): void
    {
        $this->pushQuiReussit();

        $destinataire = User::factory()->create();
        Appareil::create(['user_id' => $destinataire->id, 'jeton_push' => 'jeton-a', 'plateforme' => 'android']);

        $notification = app(ServiceNotification::class)
            ->notifier($destinataire, 'Titre', 'Corps', 'test');

        $this->assertSame('push', $notification->canal);
        $this->assertFalse((bool) $notification->repli_sms_envoye);
    }

    public function test_un_sms_de_secours_part_quand_le_push_echoue(): void
    {
        $this->pushQuiEchoue();

        $sms = Mockery::mock(SmsSender::class);
        $sms->shouldReceive('send')->once()->andReturn(true);
        $this->instance(SmsSender::class, $sms);

        $destinataire = User::factory()->create();
        Appareil::create(['user_id' => $destinataire->id, 'jeton_push' => 'jeton-mort', 'plateforme' => 'android']);

        $notification = app(ServiceNotification::class)
            ->notifier($destinataire, 'Nouvelle mission', 'Un client vous sollicite', 'demande_recue', important: true);

        $this->assertSame('sms', $notification->canal);
        $this->assertTrue((bool) $notification->repli_sms_envoye);
    }

    public function test_une_notification_ordinaire_ne_declenche_pas_de_sms(): void
    {
        $this->pushQuiEchoue();

        $sms = Mockery::mock(SmsSender::class);
        // Le SMS coûte cher : un simple message ne le justifie pas.
        $sms->shouldNotReceive('send');
        $this->instance(SmsSender::class, $sms);

        $destinataire = User::factory()->create();
        Appareil::create(['user_id' => $destinataire->id, 'jeton_push' => 'jeton-mort', 'plateforme' => 'android']);

        $notification = app(ServiceNotification::class)
            ->notifier($destinataire, 'Nouveau message', 'Bonjour', 'message');

        $this->assertSame('push', $notification->canal);
    }

    public function test_un_destinataire_sans_appareil_recoit_le_sms_si_c_est_important(): void
    {
        $sms = Mockery::mock(SmsSender::class);
        $sms->shouldReceive('send')->once()->andReturn(true);
        $this->instance(SmsSender::class, $sms);

        $notification = app(ServiceNotification::class)
            ->notifier(User::factory()->create(), 'Mission', 'Corps', 'demande_recue', important: true);

        $this->assertSame('sms', $notification->canal);
    }

    public function test_les_jetons_refuses_par_firebase_sont_supprimes(): void
    {
        $destinataire = User::factory()->create();
        Appareil::create(['user_id' => $destinataire->id, 'jeton_push' => 'jeton-mort', 'plateforme' => 'android']);
        Appareil::create(['user_id' => $destinataire->id, 'jeton_push' => 'jeton-vivant', 'plateforme' => 'ios']);

        $this->instance(EnvoyeurPush::class, Mockery::mock(EnvoyeurPush::class, function ($mock) {
            $mock->shouldReceive('envoyer')->andReturn([
                'envoyes' => 1,
                'jetons_invalides' => ['jeton-mort'],
            ]);
        }));

        app(ServiceNotification::class)->notifier($destinataire, 'Titre', 'Corps');

        // Un jeton refusé ne redeviendra jamais valide : le garder ralentit
        // chaque envoi suivant.
        $this->assertDatabaseMissing('appareils', ['jeton_push' => 'jeton-mort']);
        $this->assertDatabaseHas('appareils', ['jeton_push' => 'jeton-vivant']);
    }

    // ------------------------------------------------------------------
    // Déclenchement par les parcours métier
    // ------------------------------------------------------------------

    public function test_une_demande_de_devis_notifie_l_artisan(): void
    {
        $this->pushQuiReussit();

        $client = User::factory()->create(['role' => User::ROLE_CLIENT]);
        $artisan = Artisan::factory()->create(['statut_validation' => Artisan::VALIDATION_VALIDE]);

        Sanctum::actingAs($client);

        $this->postJson('/api/v1/demandes', [
            'artisan_id' => $artisan->id,
            'titre' => 'Fuite sous l évier',
            'description' => 'Fuite sous l évier de la cuisine depuis deux jours.',
            'adresse' => 'Bacongo, Brazzaville',
        ])->assertCreated();

        $this->assertDatabaseHas('notifications', [
            'artisan_id' => $artisan->utilisateur_id,
            'type' => 'demande_recue',
        ]);
    }

    public function test_l_acceptation_notifie_le_client(): void
    {
        $this->pushQuiReussit();

        $client = User::factory()->create(['role' => User::ROLE_CLIENT]);
        $artisan = Artisan::factory()->create(['statut_validation' => Artisan::VALIDATION_VALIDE]);
        $demande = DemandeDevis::factory()->create([
            'client_id' => $client->id,
            'artisan_id' => $artisan->id,
        ]);

        Sanctum::actingAs($artisan->utilisateur);
        $this->postJson("/api/v1/demandes/{$demande->id}/accepter", ['montant_propose' => 30000])->assertOk();

        $this->assertDatabaseHas('notifications', [
            'artisan_id' => $client->id,
            'type' => 'demande_acceptee',
        ]);
    }

    // ------------------------------------------------------------------
    // Consultation
    // ------------------------------------------------------------------

    public function test_chacun_ne_voit_que_ses_notifications(): void
    {
        $moi = User::factory()->create();
        $autre = User::factory()->create();

        Notification::create(['artisan_id' => $moi->id, 'title' => 'Pour moi', 'body' => '.']);
        Notification::create(['artisan_id' => $autre->id, 'title' => 'Pour lui', 'body' => '.']);

        Sanctum::actingAs($moi);

        $this->getJson('/api/v1/notifications')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.titre', 'Pour moi')
            ->assertJsonPath('nonLues', 1);
    }

    public function test_une_notification_se_marque_lue(): void
    {
        Sanctum::actingAs($moi = User::factory()->create());
        $notification = Notification::create(['artisan_id' => $moi->id, 'title' => 'T', 'body' => 'C']);

        $this->postJson("/api/v1/notifications/{$notification->id}/lu")->assertOk();

        $this->assertNotNull($notification->fresh()->lu_at);
        $this->getJson('/api/v1/notifications')->assertJsonPath('nonLues', 0);
    }

    public function test_on_ne_peut_pas_marquer_lue_la_notification_d_un_autre(): void
    {
        $autre = User::factory()->create();
        $notification = Notification::create(['artisan_id' => $autre->id, 'title' => 'T', 'body' => 'C']);

        Sanctum::actingAs(User::factory()->create());

        $this->postJson("/api/v1/notifications/{$notification->id}/lu")->assertForbidden();
        $this->assertNull($notification->fresh()->lu_at);
    }
}
