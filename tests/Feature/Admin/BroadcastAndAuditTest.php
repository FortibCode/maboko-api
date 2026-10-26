<?php

namespace Tests\Feature\Admin;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BroadcastAndAuditTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Sanctum::actingAs(User::factory()->admin()->create());
    }

    public function test_admin_peut_diffuser_une_notification_aux_artisans(): void
    {
        User::factory()->artisan()->count(3)->create(['statut' => User::STATUT_ACTIF]);
        User::factory()->count(2)->create(['statut' => User::STATUT_ACTIF]);

        $reponse = $this->postJson('/api/v1/admin/notifications/broadcast', [
            'cible' => 'artisans',
            'titre' => 'Mise à jour tarifaire',
            'corps' => 'Veuillez consulter les nouvelles conditions.',
            'important' => true,
        ])->assertOk();

        $reponse->assertJsonPath('destinataires', 3);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'notification.diffusee',
        ]);
    }

    public function test_admin_peut_consulter_les_logs_d_audit(): void
    {
        $admin = User::factory()->admin()->create();

        AuditLog::create([
            'user_id' => $admin->id,
            'action' => 'compte.suspendu',
            'adresse_ip' => '127.0.0.1',
        ]);

        $reponse = $this->getJson('/api/v1/admin/audit-logs')->assertOk();

        $reponse->assertJsonCount(1, 'data');
        $reponse->assertJsonPath('data.0.action', 'compte.suspendu');
    }
}
