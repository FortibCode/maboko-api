<?php

namespace Tests\Feature\Securite;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EnTetesTest extends TestCase
{
    use RefreshDatabase;

    public function test_les_reponses_portent_les_en_tetes_de_securite(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $reponse = $this->getJson('/api/v1/user');

        $reponse->assertHeader('X-Content-Type-Options', 'nosniff');
        $reponse->assertHeader('X-Frame-Options', 'DENY');
        $reponse->assertHeader('Referrer-Policy', 'no-referrer');
        $reponse->assertHeader('Content-Security-Policy');
    }

    public function test_hsts_n_est_pas_impose_en_clair(): void
    {
        // L'imposer en HTTP rendrait l'API injoignable en développement local.
        $this->getJson('/api/v1/user')->assertHeaderMissing('Strict-Transport-Security');
    }

    public function test_les_erreurs_ne_divulguent_pas_la_pile_d_appels(): void
    {
        config(['app.debug' => false]);
        Sanctum::actingAs(User::factory()->create());

        $reponse = $this->getJson('/api/v1/artisans/999999');

        $reponse->assertNotFound();
        $this->assertStringNotContainsString('vendor/laravel', $reponse->content());
        $this->assertStringNotContainsString('/home/', $reponse->content());
    }
}
