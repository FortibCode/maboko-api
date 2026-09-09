<?php

namespace Tests\Feature\Modele;

use App\Models\Artisan;
use App\Models\Badge;
use App\Models\Metier;
use App\Models\Plan;
use App\Models\Tarif;
use App\Models\User;
use Database\Seeders\BadgeSeeder;
use Database\Seeders\MetierSeeder;
use Database\Seeders\PlanSeeder;
use Database\Seeders\TarifSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReferentielTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([MetierSeeder::class, BadgeSeeder::class, PlanSeeder::class, TarifSeeder::class]);
    }

    public function test_le_referentiel_des_metiers_est_complet(): void
    {
        // Le cahier de charges annonce « une vingtaine de metiers » (§4.1).
        $this->assertGreaterThanOrEqual(20, Metier::count());

        foreach (['macon', 'plombier', 'menuisier', 'couturier', 'mecanicien', 'electricien', 'peintre', 'frigoriste'] as $slug) {
            $this->assertDatabaseHas('metiers', ['slug' => $slug]);
        }
    }

    public function test_les_sept_badges_du_cahier_de_charges_existent(): void
    {
        $attendus = ['nouveau', 'confirme', 'maitre-artisan', 'profil-verifie', 'certifie-maboko', 'recommande', 'atelier-reconnu'];

        $this->assertSame(7, Badge::count());
        foreach ($attendus as $slug) {
            $this->assertDatabaseHas('badges', ['slug' => $slug]);
        }
    }

    public function test_les_quatre_formules_d_abonnement_existent(): void
    {
        $this->assertSame(4, Plan::count());

        // Le boost de classement doit croitre avec la formule (§4.5).
        $boosts = Plan::orderBy('ordre')->pluck('boost_classement')->map(fn ($b) => (float) $b)->all();
        $this->assertSame($boosts, collect($boosts)->sort()->values()->all());
    }

    public function test_un_artisan_porte_metiers_badges_et_abonnement(): void
    {
        $artisan = Artisan::factory()->create(['statut_validation' => Artisan::VALIDATION_VALIDE]);

        $artisan->metiers()->attach(Metier::where('slug', 'menuisier')->first(), ['principal' => true]);
        $artisan->badges()->attach(Badge::where('slug', 'confirme')->first(), ['obtenu_at' => now()]);

        $this->assertSame('Menuisier', $artisan->metiers()->first()->nom);
        $this->assertSame('Confirmé', $artisan->badges()->first()->nom);
    }

    public function test_la_recherche_filtre_par_metier_et_par_proximite(): void
    {
        // Bacongo, Brazzaville
        $proche = Artisan::factory()->create([
            'statut_validation' => Artisan::VALIDATION_VALIDE,
            'latitude' => -4.2894, 'longitude' => 15.2429,
        ]);
        // Pointe-Noire, a plus de 350 km
        $loin = Artisan::factory()->create([
            'statut_validation' => Artisan::VALIDATION_VALIDE,
            'latitude' => -4.7889, 'longitude' => 11.8653,
        ]);

        $menuisier = Metier::where('slug', 'menuisier')->first();
        $proche->metiers()->attach($menuisier);
        $loin->metiers()->attach($menuisier);

        $resultats = Artisan::valides()
            ->parMetier('menuisier')
            ->aProximite(-4.2894, 15.2429, 25)
            ->get();

        $this->assertTrue($resultats->contains('id', $proche->id));
        $this->assertFalse($resultats->contains('id', $loin->id), 'Un artisan de Pointe-Noire remonte dans un rayon de 25 km.');
    }

    public function test_un_artisan_non_valide_n_apparait_pas_dans_la_recherche(): void
    {
        $enAttente = Artisan::factory()->create(['statut_validation' => Artisan::VALIDATION_EN_ATTENTE]);

        $this->assertFalse(Artisan::valides()->get()->contains('id', $enAttente->id));
    }

    public function test_le_tarif_applique_le_minimum_de_course(): void
    {
        $moto = Tarif::where('type_vehicule', 'moto')->first();

        // Course tres courte : le plancher doit s'appliquer.
        $this->assertSame(500.0, $moto->estimer(0.2, 1));

        // Course de 5 km : base 300 + 5x150 + duree.
        $this->assertGreaterThan(1000, $moto->estimer(5, 15));
    }

    public function test_un_artisan_est_rattache_a_son_utilisateur(): void
    {
        $artisan = Artisan::factory()->create();

        $this->assertSame(User::ROLE_ARTISAN, $artisan->utilisateur->role);
    }
}
