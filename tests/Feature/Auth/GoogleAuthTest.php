<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Services\Auth\VerificateurJetonGoogle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * Connexion Google (§7.1).
 *
 * L'identité provient exclusivement du jeton vérifié : jamais de champs
 * fournis en clair par le client. Sans cela, un simple POST portant
 * l'adresse d'un tiers suffirait à obtenir un jeton d'accès pour son compte.
 */
class GoogleAuthTest extends TestCase
{
    use RefreshDatabase;

    private function verificateurQuiRepond(?array $identite): void
    {
        $this->instance(VerificateurJetonGoogle::class, Mockery::mock(VerificateurJetonGoogle::class, function ($mock) use ($identite) {
            $mock->shouldReceive('verifier')->andReturn($identite);
        }));
    }

    public function test_un_jeton_valide_cree_le_compte(): void
    {
        $this->verificateurQuiRepond([
            'sub' => 'google-uid-123456',
            'email' => 'jean.dupont@gmail.com',
            'nom' => 'Dupont',
            'prenom' => 'Jean',
            'avatarUrl' => 'https://exemple.cg/avatar.jpg',
        ]);

        $reponse = $this->postJson('/api/v1/login/google', ['id_token' => 'jeton-signe-valide'])
            ->assertOk();

        $reponse->assertJsonStructure(['token', 'user', 'message']);
        $this->assertDatabaseHas('users', [
            'email' => 'jean.dupont@gmail.com',
            'role' => User::ROLE_CLIENT,
            'is_verified' => true,
        ]);
    }

    public function test_un_jeton_invalide_est_refuse(): void
    {
        // Signature invalide, expiré, ou audience incorrecte : le
        // vérificateur renvoie null dans tous ces cas.
        $this->verificateurQuiRepond(null);

        $this->postJson('/api/v1/login/google', ['id_token' => 'jeton-invente'])
            ->assertStatus(401);

        $this->assertDatabaseCount('users', 0);
    }

    public function test_le_serveur_ignore_toute_identite_fournie_par_le_client(): void
    {
        // Le vérificateur (donc le jeton signé) fait autorité : peu importe
        // ce que le corps de la requête prétend par ailleurs.
        $this->verificateurQuiRepond([
            'sub' => 'google-uid-reel',
            'email' => 'titulaire.reel@gmail.com',
            'nom' => 'Titulaire',
            'prenom' => 'Réel',
            'avatarUrl' => null,
        ]);

        $this->postJson('/api/v1/login/google', [
            'id_token' => 'jeton-signe-valide',
            'email' => 'victime@gmail.com',
            'google_id' => 'usurpation',
        ])->assertOk();

        $this->assertDatabaseHas('users', ['email' => 'titulaire.reel@gmail.com']);
        $this->assertDatabaseMissing('users', ['email' => 'victime@gmail.com']);
    }

    public function test_un_compte_existant_se_connecte_sans_en_recreer_un_second(): void
    {
        $existant = User::factory()->create(['email' => 'jean.dupont@gmail.com']);

        $this->verificateurQuiRepond([
            'sub' => 'google-uid-123456',
            'email' => 'jean.dupont@gmail.com',
            'nom' => 'Dupont',
            'prenom' => 'Jean',
            'avatarUrl' => null,
        ]);

        $reponse = $this->postJson('/api/v1/login/google', ['id_token' => 'jeton-signe-valide'])
            ->assertOk();

        $this->assertSame(1, User::where('email', 'jean.dupont@gmail.com')->count());
        $this->assertSame($existant->id, $reponse->json('user.id'));
    }

    public function test_un_compte_suspendu_ne_peut_pas_se_connecter_via_google(): void
    {
        User::factory()->create(['email' => 'suspendu@gmail.com', 'statut' => User::STATUT_SUSPENDU]);

        $this->verificateurQuiRepond([
            'sub' => 'google-uid-suspendu',
            'email' => 'suspendu@gmail.com',
            'nom' => 'Suspendu',
            'prenom' => null,
            'avatarUrl' => null,
        ]);

        $this->postJson('/api/v1/login/google', ['id_token' => 'jeton-signe-valide'])
            ->assertStatus(403);
    }

    public function test_le_jeton_est_obligatoire(): void
    {
        $this->postJson('/api/v1/login/google', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('id_token');
    }

    public function test_deux_comptes_google_places_apres_l_autre_n_ont_pas_le_meme_telephone_espace_reserve(): void
    {
        $this->verificateurQuiRepond([
            'sub' => 'a', 'email' => 'un@gmail.com', 'nom' => 'Un', 'prenom' => null, 'avatarUrl' => null,
        ]);
        $this->postJson('/api/v1/login/google', ['id_token' => 't1'])->assertOk();

        $this->verificateurQuiRepond([
            'sub' => 'b', 'email' => 'deux@gmail.com', 'nom' => 'Deux', 'prenom' => null, 'avatarUrl' => null,
        ]);
        $this->postJson('/api/v1/login/google', ['id_token' => 't2'])->assertOk();

        $telephones = User::pluck('telephone');
        $this->assertSame($telephones->count(), $telephones->unique()->count());
    }
}
