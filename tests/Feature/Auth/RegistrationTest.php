<?php

namespace Tests\Feature\Auth;

use App\Models\OtpCode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    private function formulaire(array $remplacements = []): array
    {
        return array_merge([
            'nom' => 'Jean Makaya',
            'email' => 'jean'.uniqid().'@example.cg',
            'telephone' => '+242061111111',
            'password' => 'MotDePasse1!',
        ], $remplacements);
    }

    public function test_le_code_otp_n_est_pas_renvoye_dans_la_reponse(): void
    {
        config(['sms.expose_otp_in_response' => false]);

        $reponse = $this->postJson('/api/v1/send-register-otp', $this->formulaire());

        $reponse->assertOk();
        $reponse->assertJsonMissing(['simulation_code' => true]);
        $this->assertArrayNotHasKey('simulation_code', $reponse->json());
        $this->assertArrayNotHasKey('debug_code', $reponse->json());
        $this->assertArrayNotHasKey('code', $reponse->json());
    }

    public function test_aucun_compte_n_est_cree_avant_verification(): void
    {
        $this->postJson('/api/v1/send-register-otp', $this->formulaire())->assertOk();

        $this->assertDatabaseCount('users', 0);
    }

    public function test_le_role_artisan_est_bien_enregistre_en_base(): void
    {
        $formulaire = $this->formulaire(['role' => User::ROLE_ARTISAN]);

        $this->postJson('/api/v1/send-register-otp', $formulaire)->assertOk();

        $code = OtpCode::where('telephone', $formulaire['telephone'])->latest()->first()->code;

        $this->postJson('/api/v1/verify-register-otp', [
            'telephone' => $formulaire['telephone'],
            'code' => $code,
        ])->assertCreated();

        $this->assertSame(
            User::ROLE_ARTISAN,
            User::where('telephone', $formulaire['telephone'])->first()->role,
            "L'artisan a ete enregistre comme client : le role n'est pas transmis."
        );
    }

    public function test_un_role_fantaisiste_est_refuse(): void
    {
        $this->postJson('/api/v1/send-register-otp', $this->formulaire(['role' => 'super_admin_pirate']))
            ->assertStatus(422);
    }

    public function test_deux_homonymes_peuvent_s_inscrire(): void
    {
        $premier = $this->formulaire(['telephone' => '+242061111111']);
        $second = $this->formulaire(['telephone' => '+242062222222']);

        $this->postJson('/api/v1/send-register-otp', $premier)->assertOk();
        $code = OtpCode::where('telephone', $premier['telephone'])->latest()->first()->code;
        $this->postJson('/api/v1/verify-register-otp', [
            'telephone' => $premier['telephone'],
            'code' => $code,
        ])->assertCreated();

        // Meme nom, e-mail et telephone differents : l'inscription doit passer.
        $this->postJson('/api/v1/send-register-otp', $second)
            ->assertOk();
    }

    public function test_un_numero_deja_inscrit_est_refuse(): void
    {
        $formulaire = $this->formulaire();

        $this->postJson('/api/v1/send-register-otp', $formulaire)->assertOk();
        $code = OtpCode::where('telephone', $formulaire['telephone'])->latest()->first()->code;
        $this->postJson('/api/v1/verify-register-otp', [
            'telephone' => $formulaire['telephone'],
            'code' => $code,
        ])->assertCreated();

        $this->postJson('/api/v1/send-register-otp', $this->formulaire([
            'telephone' => $formulaire['telephone'],
        ]))->assertStatus(422)->assertJsonValidationErrors('telephone');
    }

    public function test_un_numero_hors_format_congolais_est_refuse(): void
    {
        $this->postJson('/api/v1/send-register-otp', $this->formulaire(['telephone' => '+33612345678']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('telephone');
    }

    public function test_le_client_ne_peut_pas_changer_de_role_entre_les_deux_etapes(): void
    {
        $formulaire = $this->formulaire(['role' => User::ROLE_CLIENT]);

        $this->postJson('/api/v1/send-register-otp', $formulaire)->assertOk();
        $code = OtpCode::where('telephone', $formulaire['telephone'])->latest()->first()->code;

        $this->postJson('/api/v1/verify-register-otp', [
            'telephone' => $formulaire['telephone'],
            'code' => $code,
            'role' => User::ROLE_ADMIN,
        ])->assertCreated();

        $this->assertSame(
            User::ROLE_CLIENT,
            User::where('telephone', $formulaire['telephone'])->first()->role
        );
    }
}
