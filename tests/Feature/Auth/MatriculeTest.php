<?php

namespace Tests\Feature\Auth;

use App\Models\Artisan;
use App\Models\User;
use App\Services\OtpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Matricule Maboko : attribué à tout compte, à la création, et visible par
 * son titulaire.
 */
class MatriculeTest extends TestCase
{
    use RefreshDatabase;

    public function test_le_matricule_suit_le_role_et_le_numero_du_compte(): void
    {
        $this->assertSame('MBK-ART-00042', User::matriculePour(42, User::ROLE_ARTISAN));
        $this->assertSame('MBK-CHA-00007', User::matriculePour(7, User::ROLE_CHAUFFEUR));
        $this->assertSame('MBK-CLI-123456', User::matriculePour(123456, User::ROLE_CLIENT));
        $this->assertSame('MBK-ADM-00001', User::matriculePour(1, User::ROLE_SUPER_ADMIN));
    }

    public function test_tout_compte_cree_recoit_son_matricule(): void
    {
        $client = User::factory()->create(['role' => User::ROLE_CLIENT]);
        $artisan = User::factory()->artisan()->create();

        $this->assertSame(User::matriculePour($client->id, 'client'), $client->fresh()->matricule);
        $this->assertStringStartsWith('MBK-ART-', $artisan->fresh()->matricule);
    }

    public function test_l_inscription_par_sms_attribue_un_matricule(): void
    {
        $code = app(OtpService::class)->emettrePourInscription([
            'nom' => 'Mbemba',
            'email' => 'mbemba@exemple.cg',
            'telephone' => '+242061234567',
            'password' => 'motdepasse',
            'role' => User::ROLE_ARTISAN,
        ]);

        $this->postJson('/api/v1/verify-register-otp', ['telephone' => '+242061234567', 'code' => $code])
            ->assertCreated()
            ->assertJsonPath('user.matricule', fn ($m) => str_starts_with($m, 'MBK-ART-'));
    }

    public function test_un_compte_ajoute_a_son_propre_matricule(): void
    {
        $principal = User::factory()->artisan()->create();
        Artisan::creerFicheProvisoire($principal);
        Sanctum::actingAs($principal);

        $reponse = $this->postJson('/api/v1/comptes', ['nom' => 'Couture Mama'])->assertCreated();

        $this->assertStringStartsWith('MBK-ART-', $reponse->json('user.matricule'));
        $this->assertNotSame($principal->fresh()->matricule, $reponse->json('user.matricule'));
    }

    public function test_le_titulaire_voit_son_matricule(): void
    {
        $compte = User::factory()->create();
        Sanctum::actingAs($compte->fresh());

        $this->getJson('/api/v1/user')->assertJsonPath('matricule', $compte->fresh()->matricule);
    }
}
