<?php

namespace Tests\Feature\Auth;

use App\Models\OtpCode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    private function creerUtilisateur(string $telephone = '+242061111111'): User
    {
        return User::create([
            'nom' => 'Jean Makaya',
            'email' => 'jean.'.uniqid().'@example.cg',
            'telephone' => $telephone,
            'password' => 'AncienMotDePasse1!',
            'role' => User::ROLE_CLIENT,
        ]);
    }

    public function test_le_mot_de_passe_ne_peut_pas_etre_change_sans_jeton(): void
    {
        $user = $this->creerUtilisateur();

        $reponse = $this->postJson('/api/v1/reset-password', [
            'telephone' => $user->telephone,
            'password' => 'NouveauMotDePasse1!',
            'password_confirmation' => 'NouveauMotDePasse1!',
        ]);

        $reponse->assertStatus(422);

        $this->assertTrue(
            Hash::check('AncienMotDePasse1!', $user->fresh()->password),
            'Le mot de passe a ete modifie sans jeton : la faille est toujours ouverte.'
        );
    }

    public function test_un_jeton_invente_est_refuse(): void
    {
        $user = $this->creerUtilisateur('+242062222222');

        $this->postJson('/api/v1/reset-password', [
            'telephone' => $user->telephone,
            'reset_token' => str_repeat('a', 64),
            'password' => 'NouveauMotDePasse1!',
            'password_confirmation' => 'NouveauMotDePasse1!',
        ])->assertStatus(422);

        $this->assertTrue(Hash::check('AncienMotDePasse1!', $user->fresh()->password));
    }

    public function test_le_parcours_complet_change_bien_le_mot_de_passe(): void
    {
        $user = $this->creerUtilisateur('+242063333333');

        $this->postJson('/api/v1/send-otp', ['telephone' => $user->telephone])
            ->assertOk();

        $code = OtpCode::where('telephone', $user->telephone)->latest()->first()->code;

        $jeton = $this->postJson('/api/v1/verify-otp', [
            'telephone' => $user->telephone,
            'code' => $code,
        ])->assertOk()->json('reset_token');

        $this->assertNotEmpty($jeton);

        $this->postJson('/api/v1/reset-password', [
            'telephone' => $user->telephone,
            'reset_token' => $jeton,
            'password' => 'NouveauMotDePasse1!',
            'password_confirmation' => 'NouveauMotDePasse1!',
        ])->assertOk();

        $user->refresh();
        $this->assertTrue(Hash::check('NouveauMotDePasse1!', $user->password));
        $this->assertFalse(Hash::check('AncienMotDePasse1!', $user->password));
    }

    public function test_le_jeton_ne_sert_qu_une_seule_fois(): void
    {
        $user = $this->creerUtilisateur('+242064444444');

        $this->postJson('/api/v1/send-otp', ['telephone' => $user->telephone]);
        $code = OtpCode::where('telephone', $user->telephone)->latest()->first()->code;

        $jeton = $this->postJson('/api/v1/verify-otp', [
            'telephone' => $user->telephone,
            'code' => $code,
        ])->json('reset_token');

        $this->postJson('/api/v1/reset-password', [
            'telephone' => $user->telephone,
            'reset_token' => $jeton,
            'password' => 'PremierChangement1!',
            'password_confirmation' => 'PremierChangement1!',
        ])->assertOk();

        $this->postJson('/api/v1/reset-password', [
            'telephone' => $user->telephone,
            'reset_token' => $jeton,
            'password' => 'SecondChangement1!',
            'password_confirmation' => 'SecondChangement1!',
        ])->assertStatus(422);

        $this->assertTrue(Hash::check('PremierChangement1!', $user->fresh()->password));
    }

    public function test_le_changement_de_mot_de_passe_revoque_les_sessions_ouvertes(): void
    {
        $user = $this->creerUtilisateur('+242065555555');
        $ancienJeton = $user->createToken('auth_token')->plainTextToken;

        $this->postJson('/api/v1/send-otp', ['telephone' => $user->telephone]);
        $code = OtpCode::where('telephone', $user->telephone)->latest()->first()->code;

        $jeton = $this->postJson('/api/v1/verify-otp', [
            'telephone' => $user->telephone,
            'code' => $code,
        ])->json('reset_token');

        $this->postJson('/api/v1/reset-password', [
            'telephone' => $user->telephone,
            'reset_token' => $jeton,
            'password' => 'NouveauMotDePasse1!',
            'password_confirmation' => 'NouveauMotDePasse1!',
        ])->assertOk();

        $this->withHeader('Authorization', 'Bearer '.$ancienJeton)
            ->getJson('/api/v1/user')
            ->assertStatus(401);
    }

    public function test_l_endpoint_ne_revele_pas_si_un_numero_est_inscrit(): void
    {
        $inconnu = $this->postJson('/api/v1/send-otp', ['telephone' => '+242069999999']);
        $connu = $this->postJson('/api/v1/send-otp', ['telephone' => $this->creerUtilisateur('+242066666666')->telephone]);

        $inconnu->assertOk();
        $connu->assertOk();
        $this->assertSame($inconnu->json('message'), $connu->json('message'));
    }
}
