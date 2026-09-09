<?php

namespace Tests\Feature\Chauffeur;

use App\Models\User;
use App\Services\TarificationCourse;
use Database\Seeders\TarifSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TarificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(TarifSeeder::class);
    }

    public function test_la_distance_entre_deux_points_de_brazzaville(): void
    {
        $service = app(TarificationCourse::class);

        // Bacongo → Mpila : environ 6 km à vol d'oiseau.
        $distance = $service->distanceKm(-4.2894, 15.2429, -4.2610, 15.2900);

        $this->assertGreaterThan(4, $distance);
        $this->assertLessThan(9, $distance);
    }

    public function test_la_moto_coute_moins_cher_que_la_voiture(): void
    {
        $service = app(TarificationCourse::class);

        $moto = $service->estimer(-4.2894, 15.2429, -4.2610, 15.2900, 'moto');
        $voiture = $service->estimer(-4.2894, 15.2429, -4.2610, 15.2900, 'voiture');

        $this->assertLessThan($voiture['tarif'], $moto['tarif']);
    }

    public function test_la_distance_estimee_depasse_le_vol_d_oiseau(): void
    {
        $service = app(TarificationCourse::class);

        $volDOiseau = $service->distanceKm(-4.2894, 15.2429, -4.2610, 15.2900);
        $estimation = $service->estimer(-4.2894, 15.2429, -4.2610, 15.2900, 'moto');

        // Les rues de Brazzaville ne sont pas un damier.
        $this->assertGreaterThan($volDOiseau, $estimation['distanceKm']);
    }

    public function test_une_course_tres_courte_applique_le_minimum(): void
    {
        $service = app(TarificationCourse::class);

        // Deux points quasiment confondus.
        $estimation = $service->estimer(-4.2894, 15.2429, -4.2895, 15.2430, 'moto');

        $this->assertSame(500.0, $estimation['tarif']);
    }

    public function test_l_endpoint_d_estimation_renvoie_le_detail(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/v1/courses/estimation', [
            'depart_latitude' => -4.2894,
            'depart_longitude' => 15.2429,
            'arrivee_latitude' => -4.2610,
            'arrivee_longitude' => 15.2900,
            'type_vehicule' => 'moto',
        ])
            ->assertOk()
            ->assertJsonStructure(['distanceKm', 'dureeMin', 'tarif', 'tarifDetail' => ['base', 'parKm', 'minimum']]);
    }

    public function test_un_type_de_vehicule_inconnu_est_refuse(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/v1/courses/estimation', [
            'depart_latitude' => -4.2894,
            'depart_longitude' => 15.2429,
            'arrivee_latitude' => -4.2610,
            'arrivee_longitude' => 15.2900,
            'type_vehicule' => 'helicoptere',
        ])->assertStatus(422)->assertJsonValidationErrors('type_vehicule');
    }

    public function test_l_estimation_exige_une_authentification(): void
    {
        $this->postJson('/api/v1/courses/estimation', [])->assertStatus(401);
    }
}
