<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Absence de passerelle SMS en production (§7.1).
 *
 * Le canal « log » ecrit le code dans les journaux du serveur. En
 * developpement c'est commode ; en production l'API repondait « Code de
 * validation envoye par SMS » alors que rien ne partait, et l'inscription
 * restait bloquee sur l'ecran du code sans explication.
 */
class PasserelleSmsTest extends TestCase
{
    use RefreshDatabase;

    /** @param array<string, mixed> $remplacements */
    private function inscription(array $remplacements = []): array
    {
        return array_merge([
            'nom' => 'Essai Maboko',
            'email' => 'essai@example.org',
            'telephone' => '+242061234567',
            'password' => 'Maboko2026!',
            'role' => User::ROLE_CLIENT,
        ], $remplacements);
    }

    public function test_hors_production_le_canal_journal_reste_accepte(): void
    {
        config(['sms.driver' => 'log']);

        $this->postJson('/api/v1/send-register-otp', $this->inscription())
            ->assertOk();
    }

    public function test_en_production_sans_passerelle_l_api_refuse_clairement(): void
    {
        config(['app.env' => 'production', 'sms.driver' => 'log']);

        $this->postJson('/api/v1/send-register-otp', $this->inscription())
            ->assertStatus(503)
            ->assertJsonPath('code', 'passerelle_sms_absente');
    }

    public function test_le_mot_de_passe_oublie_refuse_aussi(): void
    {
        User::factory()->create(['telephone' => '+242061234567']);

        config(['app.env' => 'production', 'sms.driver' => 'log']);

        $this->postJson('/api/v1/send-otp', ['telephone' => '+242061234567'])
            ->assertStatus(503)
            ->assertJsonPath('code', 'passerelle_sms_absente');
    }
}
