<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
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

    public function test_un_numero_de_test_recoit_son_code_dans_la_reponse(): void
    {
        config([
            'app.env' => 'production',
            'sms.driver' => 'log',
            'sms.numeros_test' => ['+242061234567'],
        ]);

        $this->postJson('/api/v1/send-register-otp', $this->inscription())
            ->assertOk()
            ->assertJsonStructure(['message', 'debug_code']);
    }

    public function test_le_code_d_un_numero_de_test_ouvre_bien_le_compte(): void
    {
        config([
            'app.env' => 'production',
            'sms.driver' => 'log',
            'sms.numeros_test' => ['+242061234567'],
        ]);

        $code = $this->postJson('/api/v1/send-register-otp', $this->inscription())
            ->assertOk()
            ->json('debug_code');

        $this->postJson('/api/v1/verify-register-otp', [
            'telephone' => '+242061234567',
            'code' => $code,
        ])->assertCreated()->assertJsonStructure(['token', 'user']);

        $this->assertDatabaseHas('users', ['telephone' => '+242061234567']);
    }

    public function test_les_autres_numeros_restent_refuses(): void
    {
        config([
            'app.env' => 'production',
            'sms.driver' => 'log',
            'sms.numeros_test' => ['+242061234567'],
        ]);

        // Un numero qui n'est pas dans la liste ne doit pas profiter de la
        // porte ouverte aux numeros de test.
        $this->postJson('/api/v1/send-register-otp', $this->inscription([
            'telephone' => '+242069999999',
        ]))->assertStatus(503);
    }

    public function test_aucun_code_ne_fuit_quand_la_liste_est_vide(): void
    {
        config(['sms.driver' => 'log', 'sms.numeros_test' => []]);

        $this->postJson('/api/v1/send-register-otp', $this->inscription())
            ->assertOk()
            ->assertJsonMissing(['debug_code']);
    }

    /**
     * @return list<array{0: string}>
     */
    public static function formatsDeNumero(): array
    {
        return [['+242061234567'], ['242061234567'], ['061234567'], ['+242 06 12 34 567']];
    }

    #[DataProvider('formatsDeNumero')]
    public function test_le_numero_de_test_est_reconnu_quel_que_soit_son_format(string $ecriture): void
    {
        config([
            'app.env' => 'production',
            'sms.driver' => 'log',
            'sms.numeros_test' => [$ecriture],
        ]);

        // L'application envoie toujours « +242061234567 » ; la variable
        // d'environnement, elle, peut avoir ete recopiee autrement.
        $this->postJson('/api/v1/send-register-otp', $this->inscription())
            ->assertOk()
            ->assertJsonStructure(['debug_code']);
    }

    // ------------------------------------------------------------------
    // Phase de test ouverte : OTP_EXPOSE_IN_RESPONSE hors developpement
    // ------------------------------------------------------------------

    public function test_le_drapeau_ouvre_l_inscription_a_tout_numero(): void
    {
        config([
            'app.env' => 'production',
            'sms.driver' => 'log',
            'sms.numeros_test' => [],
            'sms.expose_otp_in_response' => true,
        ]);

        $this->postJson('/api/v1/send-register-otp', $this->inscription([
            'telephone' => '+242069999999',
        ]))->assertOk()->assertJsonStructure(['debug_code']);
    }

    public function test_mais_il_n_ouvre_jamais_la_reinitialisation_hors_developpement(): void
    {
        User::factory()->create(['telephone' => '+242061234567']);

        config([
            'app.env' => 'production',
            'sms.driver' => 'log',
            'sms.numeros_test' => [],
            'sms.expose_otp_in_response' => true,
        ]);

        // Exposer ce code-la donnerait acces a un compte existant, celui de
        // l'administration compris. Le drapeau ne s'y applique donc pas : la
        // reinitialisation exige une vraie passerelle, et a defaut elle
        // echoue franchement plutot que de livrer le code.
        $this->postJson('/api/v1/send-otp', ['telephone' => '+242061234567'])
            ->assertStatus(503)
            ->assertJsonMissing(['debug_code']);
    }

    public function test_en_developpement_la_reinitialisation_reste_commode(): void
    {
        User::factory()->create(['telephone' => '+242061234567']);

        config([
            'app.env' => 'local',
            'sms.driver' => 'log',
            'sms.expose_otp_in_response' => true,
        ]);

        $this->postJson('/api/v1/send-otp', ['telephone' => '+242061234567'])
            ->assertOk()
            ->assertJsonStructure(['debug_code']);
    }
}
