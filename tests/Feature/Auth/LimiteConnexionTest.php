<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * La limite de tentatives de connexion vise l'appareil de l'utilisateur, pas
 * le répartiteur de charge par lequel passent toutes les requêtes en ligne.
 */
class LimiteConnexionTest extends TestCase
{
    use RefreshDatabase;

    private const PROXY = '10.0.0.1';

    private function tentative(string $ipClient, string $identifiant, string $motDePasse = 'mauvais-mot')
    {
        return $this->withServerVariables(['REMOTE_ADDR' => self::PROXY])
            ->withHeader('X-Forwarded-For', $ipClient)
            ->postJson('/api/v1/login', ['identifiant' => $identifiant, 'password' => $motDePasse]);
    }

    public function test_un_appareil_bloque_ne_bloque_pas_les_autres_derriere_le_meme_proxy(): void
    {
        // 20 échecs sur 10 minutes depuis un même appareil, sur des comptes
        // différents pour ne pas déclencher la limite par identifiant.
        for ($i = 0; $i < 20; $i++) {
            $this->tentative('41.202.1.10', "inconnu{$i}@example.cg")->assertUnauthorized();
        }
        $this->tentative('41.202.1.10', 'encore@example.cg')->assertStatus(429);

        $utilisateur = User::factory()->create(['password' => 'secret123']);

        $this->tentative('41.202.1.99', $utilisateur->telephone, 'secret123')->assertOk();
    }
}
