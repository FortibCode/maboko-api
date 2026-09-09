<?php

namespace Tests\Feature\Auth;

use App\Models\OtpCode;
use App\Models\User;
use App\Services\OtpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OtpBruteForceTest extends TestCase
{
    use RefreshDatabase;

    public function test_le_code_est_invalide_apres_trop_de_tentatives(): void
    {
        $user = User::create([
            'nom' => 'Marie Loubaki',
            'email' => 'marie@example.cg',
            'telephone' => '+242067777777',
            'password' => 'MotDePasse1!',
            'role' => User::ROLE_CLIENT,
        ]);

        $this->postJson('/api/v1/send-otp', ['telephone' => $user->telephone])->assertOk();
        $bonCode = OtpCode::where('telephone', $user->telephone)->latest()->first()->code;

        $mauvaisCode = $bonCode === '000000' ? '111111' : '000000';

        for ($i = 0; $i < OtpService::MAX_TENTATIVES; $i++) {
            $this->postJson('/api/v1/verify-otp', [
                'telephone' => $user->telephone,
                'code' => $mauvaisCode,
            ])->assertStatus(422);
        }

        // Le bon code ne doit plus rien ouvrir : le tirage au sort est mort.
        $this->postJson('/api/v1/verify-otp', [
            'telephone' => $user->telephone,
            'code' => $bonCode,
        ])->assertStatus(422);
    }

    public function test_un_code_expire_est_refuse(): void
    {
        $user = User::create([
            'nom' => 'Paul Bouiti',
            'email' => 'paul@example.cg',
            'telephone' => '+242068888888',
            'password' => 'MotDePasse1!',
            'role' => User::ROLE_CLIENT,
        ]);

        $this->postJson('/api/v1/send-otp', ['telephone' => $user->telephone]);
        $otp = OtpCode::where('telephone', $user->telephone)->latest()->first();

        $this->travel(OtpService::VALIDITE_CODE_MINUTES + 1)->minutes();

        $this->postJson('/api/v1/verify-otp', [
            'telephone' => $user->telephone,
            'code' => $otp->code,
        ])->assertStatus(422);
    }
}
