<?php

namespace Tests\Feature\Marketplace;

use App\Models\Artisan;
use App\Models\Metier;
use App\Models\User;
use Database\Seeders\MetierSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Creation de la fiche artisan par son titulaire (§5.2.1).
 *
 * L'inscription d'un artisan cree une fiche provisoire en attente
 * (OtpController). Cette route la complete, ou la cree si elle manque, et
 * la valide aussitot : l'artisan apparait dans la recherche sans attendre
 * l'administration, qui peut toujours revenir sur la validation.
 */
class DepotFicheArtisanTest extends TestCase
{
    use RefreshDatabase;

    private function artisanSansFiche(): User
    {
        return User::factory()->create(['role' => User::ROLE_ARTISAN]);
    }

    /** @param array<string, mixed> $remplacements */
    private function fiche(array $remplacements = []): array
    {
        return array_merge([
            'specialite' => 'Menuisier',
            'adresse' => 'Rue Mbochis, Bacongo',
            'latitude' => -4.2634,
            'longitude' => 15.2429,
            'zone_intervention' => 'Bacongo, Makelekele',
            'rayon_km' => 15,
        ], $remplacements);
    }

    public function test_un_artisan_sans_fiche_peut_creer_la_sienne(): void
    {
        $utilisateur = $this->artisanSansFiche();
        Sanctum::actingAs($utilisateur);

        $this->postJson('/api/v1/artisans', $this->fiche())
            ->assertCreated()
            ->assertJsonPath('data.specialite', 'Menuisier');

        $this->assertDatabaseHas('artisans', [
            'utilisateur_id' => $utilisateur->id,
            'specialite' => 'Menuisier',
            'rayon_km' => 15,
        ]);
    }

    public function test_la_fiche_completee_est_validee_aussitot(): void
    {
        $utilisateur = $this->artisanSansFiche();
        Sanctum::actingAs($utilisateur);

        $this->postJson('/api/v1/artisans', $this->fiche())->assertCreated();

        $this->assertSame(
            Artisan::VALIDATION_VALIDE,
            Artisan::where('utilisateur_id', $utilisateur->id)->value('statut_validation'),
        );
    }

    public function test_la_fiche_provisoire_de_l_inscription_est_completee(): void
    {
        $utilisateur = $this->artisanSansFiche();
        Artisan::factory()->create([
            'utilisateur_id' => $utilisateur->id,
            'specialite' => 'À compléter',
            'statut_validation' => Artisan::VALIDATION_EN_ATTENTE,
        ]);
        Sanctum::actingAs($utilisateur);

        $this->postJson('/api/v1/artisans', $this->fiche())
            ->assertOk()
            ->assertJsonPath('data.specialite', 'Menuisier');

        $this->assertDatabaseHas('artisans', [
            'utilisateur_id' => $utilisateur->id,
            'specialite' => 'Menuisier',
            'statut_validation' => Artisan::VALIDATION_VALIDE,
        ]);
    }

    public function test_les_metiers_choisis_sont_rattaches(): void
    {
        $utilisateur = $this->artisanSansFiche();
        Sanctum::actingAs($utilisateur);

        $this->seed(MetierSeeder::class);
        $metiers = Metier::query()->take(2)->get();

        $this->postJson('/api/v1/artisans', $this->fiche([
            'metiers' => $metiers->pluck('slug')->all(),
        ]))->assertCreated();

        $artisan = Artisan::where('utilisateur_id', $utilisateur->id)->firstOrFail();

        $this->assertEqualsCanonicalizing(
            $metiers->pluck('id')->all(),
            $artisan->metiers()->pluck('metiers.id')->all(),
        );
    }

    public function test_le_tableau_de_bord_cesse_de_reclamer_la_fiche(): void
    {
        $utilisateur = $this->artisanSansFiche();
        Sanctum::actingAs($utilisateur);

        $this->getJson('/api/v1/tableau-de-bord')
            ->assertOk()
            ->assertJsonPath('ficheManquante', true);

        $this->postJson('/api/v1/artisans', $this->fiche())->assertCreated();

        $this->getJson('/api/v1/tableau-de-bord')
            ->assertOk()
            ->assertJsonPath('ficheManquante', false);
    }

    public function test_une_seconde_soumission_met_a_jour_la_meme_fiche(): void
    {
        $utilisateur = $this->artisanSansFiche();
        Sanctum::actingAs($utilisateur);

        $this->postJson('/api/v1/artisans', $this->fiche())->assertCreated();
        $this->postJson('/api/v1/artisans', $this->fiche(['specialite' => 'Ébéniste']))
            ->assertOk()
            ->assertJsonPath('data.specialite', 'Ébéniste');

        $this->assertSame(1, Artisan::where('utilisateur_id', $utilisateur->id)->count());
    }

    public function test_un_client_ne_peut_pas_creer_de_fiche_artisan(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => User::ROLE_CLIENT]));

        $this->postJson('/api/v1/artisans', $this->fiche())->assertForbidden();

        $this->assertDatabaseCount('artisans', 0);
    }

    public function test_les_coordonnees_sont_exigees(): void
    {
        Sanctum::actingAs($this->artisanSansFiche());

        $this->postJson('/api/v1/artisans', ['specialite' => 'Menuisier'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['adresse', 'latitude', 'longitude']);
    }

    public function test_un_metier_inconnu_est_refuse(): void
    {
        Sanctum::actingAs($this->artisanSansFiche());

        $this->postJson('/api/v1/artisans', $this->fiche([
            'metiers' => ['metier-qui-n-existe-pas'],
        ]))->assertStatus(422)->assertJsonValidationErrors(['metiers.0']);
    }

    public function test_la_creation_exige_une_authentification(): void
    {
        $this->postJson('/api/v1/artisans', $this->fiche())->assertUnauthorized();
    }
}
