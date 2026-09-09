<?php

namespace Tests\Feature\Marketplace;

use App\Models\Artisan;
use App\Models\Badge;
use App\Models\Metier;
use App\Models\User;
use Database\Seeders\BadgeSeeder;
use Database\Seeders\MetierSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RechercheArtisanTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([MetierSeeder::class, BadgeSeeder::class]);
        Sanctum::actingAs(User::factory()->create());
    }

    private function artisanAvecMetier(string $slug, array $attributs = []): Artisan
    {
        $artisan = Artisan::factory()->create(array_merge([
            'statut_validation' => Artisan::VALIDATION_VALIDE,
        ], $attributs));

        $artisan->metiers()->attach(Metier::where('slug', $slug)->first());

        return $artisan;
    }

    public function test_l_ecran_explorer_liste_les_metiers(): void
    {
        $this->getJson('/api/v1/metiers')
            ->assertOk()
            ->assertJsonStructure(['data' => [['nom', 'slug', 'icone', 'nbArtisans']]]);

        $this->assertGreaterThanOrEqual(20, count($this->getJson('/api/v1/metiers')->json('data')));
    }

    public function test_les_metiers_se_recherchent_par_nom(): void
    {
        $resultats = $this->getJson('/api/v1/metiers?q=Plomb')->assertOk()->json('data');

        $this->assertNotEmpty($resultats);
        $this->assertSame('plombier', $resultats[0]['slug']);
    }

    public function test_la_recherche_filtre_par_metier(): void
    {
        $plombier = $this->artisanAvecMetier('plombier');
        $menuisier = $this->artisanAvecMetier('menuisier');

        $ids = collect($this->getJson('/api/v1/artisans?metier=plombier')->assertOk()->json('data'))
            ->pluck('id');

        $this->assertTrue($ids->contains($plombier->id));
        $this->assertFalse($ids->contains($menuisier->id));
    }

    public function test_la_recherche_filtre_par_note_minimale(): void
    {
        $bon = $this->artisanAvecMetier('plombier', ['note_moyenne' => 4.7]);
        $moyen = $this->artisanAvecMetier('plombier', ['note_moyenne' => 3.1]);

        $ids = collect($this->getJson('/api/v1/artisans?note_min=4')->json('data'))->pluck('id');

        $this->assertTrue($ids->contains($bon->id));
        $this->assertFalse($ids->contains($moyen->id));
    }

    public function test_la_recherche_filtre_par_badge(): void
    {
        $certifie = $this->artisanAvecMetier('macon');
        $certifie->badges()->attach(Badge::where('slug', 'maitre-artisan')->first(), ['obtenu_at' => now()]);

        $ordinaire = $this->artisanAvecMetier('macon');

        $ids = collect($this->getJson('/api/v1/artisans?badge=maitre-artisan')->json('data'))->pluck('id');

        $this->assertTrue($ids->contains($certifie->id));
        $this->assertFalse($ids->contains($ordinaire->id));
    }

    public function test_la_recherche_geolocalisee_renvoie_la_distance(): void
    {
        $proche = $this->artisanAvecMetier('peintre', ['latitude' => -4.2894, 'longitude' => 15.2429]);
        $loin = $this->artisanAvecMetier('peintre', ['latitude' => -4.7889, 'longitude' => 11.8653]);

        $data = $this->getJson('/api/v1/artisans?latitude=-4.2894&longitude=15.2429&rayon_km=20')
            ->assertOk()->json('data');

        $ids = collect($data)->pluck('id');
        $this->assertTrue($ids->contains($proche->id));
        $this->assertFalse($ids->contains($loin->id));
        $this->assertArrayHasKey('distanceKm', $data[0]);
    }

    public function test_une_latitude_sans_longitude_est_refusee(): void
    {
        $this->getJson('/api/v1/artisans?latitude=-4.2894')
            ->assertStatus(422)
            ->assertJsonValidationErrors('longitude');
    }

    public function test_un_metier_inconnu_est_refuse(): void
    {
        $this->getJson('/api/v1/artisans?metier=astronaute')
            ->assertStatus(422)
            ->assertJsonValidationErrors('metier');
    }

    public function test_la_fiche_artisan_expose_metiers_badges_et_avis(): void
    {
        $artisan = $this->artisanAvecMetier('menuisier');
        $artisan->badges()->attach(Badge::where('slug', 'confirme')->first(), ['obtenu_at' => now()]);

        $this->getJson("/api/v1/artisans/{$artisan->id}")
            ->assertOk()
            ->assertJsonPath('data.metiers.0.slug', 'menuisier')
            ->assertJsonPath('data.badges.0.slug', 'confirme')
            ->assertJsonStructure(['data' => ['noteMoyenne', 'nbAvis', 'nbMissionsTerminees', 'utilisateur' => ['nomComplet']]]);
    }

    public function test_la_fiche_ne_divulgue_pas_le_telephone_de_l_artisan(): void
    {
        $artisan = $this->artisanAvecMetier('menuisier');

        $reponse = $this->getJson("/api/v1/artisans/{$artisan->id}")->assertOk();

        $this->assertArrayNotHasKey('telephone', $reponse->json('data.utilisateur'));
        $this->assertArrayNotHasKey('email', $reponse->json('data.utilisateur'));
    }

    public function test_un_artisan_met_a_jour_sa_propre_fiche(): void
    {
        $artisan = $this->artisanAvecMetier('menuisier');
        Sanctum::actingAs($artisan->utilisateur);

        $this->patchJson("/api/v1/artisans/{$artisan->id}", [
            'bio' => 'Quinze ans de métier à Brazzaville.',
            'metiers' => ['menuisier', 'ebeniste'],
        ])->assertOk()->assertJsonPath('data.bio', 'Quinze ans de métier à Brazzaville.');

        $this->assertCount(2, $artisan->fresh()->metiers);
    }

    public function test_un_artisan_ne_peut_pas_modifier_la_fiche_d_un_autre(): void
    {
        $cible = $this->artisanAvecMetier('menuisier');
        $intrus = $this->artisanAvecMetier('plombier');

        Sanctum::actingAs($intrus->utilisateur);

        $this->patchJson("/api/v1/artisans/{$cible->id}", ['bio' => 'Piraté'])
            ->assertForbidden();
    }
}
