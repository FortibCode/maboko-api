<?php

namespace Tests\Feature\Admin;

use App\Models\Metier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MetierAdminTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Sanctum::actingAs(User::factory()->admin()->create());
    }

    public function test_admin_peut_lister_les_metiers(): void
    {
        Metier::create(['nom' => 'Plombier', 'slug' => 'plombier', 'ordre' => 1]);
        Metier::create(['nom' => 'Électricien', 'slug' => 'electricien', 'ordre' => 2]);

        $reponse = $this->getJson('/api/v1/admin/metiers')
            ->assertOk();

        $reponse->assertJsonCount(2, 'data');
    }

    public function test_admin_peut_creer_un_metier(): void
    {
        $reponse = $this->postJson('/api/v1/admin/metiers', [
            'nom' => 'Menuisier',
            'description' => 'Travail du bois et agencement',
            'icone' => 'carpenter',
            'ordre' => 3,
        ])->assertCreated();

        $reponse->assertJsonPath('metier.nom', 'Menuisier');
        $this->assertDatabaseHas('metiers', ['nom' => 'Menuisier', 'slug' => 'menuisier']);
    }

    public function test_admin_peut_modifier_un_metier(): void
    {
        $metier = Metier::create(['nom' => 'Maçon', 'slug' => 'macon', 'ordre' => 1]);

        $this->putJson("/api/v1/admin/metiers/{$metier->id}", [
            'nom' => 'Maçonnerie Générale',
            'ordre' => 5,
        ])->assertOk();

        $this->assertDatabaseHas('metiers', [
            'id' => $metier->id,
            'nom' => 'Maçonnerie Générale',
        ]);
    }

    public function test_admin_peut_supprimer_un_metier_sans_artisan(): void
    {
        $metier = Metier::create(['nom' => 'Peintre', 'slug' => 'peintre', 'ordre' => 1]);

        $this->deleteJson("/api/v1/admin/metiers/{$metier->id}")
            ->assertOk();

        $this->assertDatabaseMissing('metiers', ['id' => $metier->id]);
    }

    public function test_non_admin_ne_peut_pas_acceder_aux_routes_admin_metiers(): void
    {
        Sanctum::actingAs(User::factory()->artisan()->create());

        $this->getJson('/api/v1/admin/metiers')->assertForbidden();
        $this->postJson('/api/v1/admin/metiers', ['nom' => 'Test'])->assertForbidden();
    }

    /** Un PNG minimal valide, suffisant pour exercer le decodage. */
    private const IMAGE = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    public function test_admin_peut_deposer_la_photo_d_un_metier(): void
    {
        Storage::fake('public');

        $this->postJson('/api/v1/admin/metiers', [
            'nom' => 'Soudeur',
            'image' => self::IMAGE,
        ])->assertCreated();

        $metier = Metier::where('slug', 'soudeur')->firstOrFail();

        // La colonne ne garde qu'un chemin : l'hote se decide a la lecture.
        $this->assertStringStartsWith('/storage/metiers/', $metier->getRawOriginal('image_url'));
        $this->assertStringStartsWith('http', (string) $metier->image_url);
        Storage::disk('public')->assertExists(
            str_replace('/storage/', '', $metier->getRawOriginal('image_url')),
        );
    }

    public function test_la_photo_est_exposee_a_l_application(): void
    {
        Storage::fake('public');

        $metier = Metier::create(['nom' => 'Vitrier', 'slug' => 'vitrier']);

        $this->putJson("/api/v1/admin/metiers/{$metier->id}", ['image' => self::IMAGE])
            ->assertOk();

        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/v1/metiers')
            ->assertOk()
            ->assertJsonPath('data.0.imageUrl', fn (?string $url) => is_string($url)
                && str_contains($url, '/storage/metiers/'));
    }

    public function test_enregistrer_sans_image_conserve_celle_qui_existe(): void
    {
        Storage::fake('public');

        $metier = Metier::create(['nom' => 'Carreleur', 'slug' => 'carreleur']);
        $this->putJson("/api/v1/admin/metiers/{$metier->id}", ['image' => self::IMAGE])->assertOk();

        $avant = $metier->fresh()->getRawOriginal('image_url');

        $this->putJson("/api/v1/admin/metiers/{$metier->id}", ['ordre' => 3])->assertOk();

        $this->assertSame($avant, $metier->fresh()->getRawOriginal('image_url'));
    }

    public function test_une_chaine_vide_retire_la_photo(): void
    {
        Storage::fake('public');

        $metier = Metier::create(['nom' => 'Peintre', 'slug' => 'peintre']);
        $this->putJson("/api/v1/admin/metiers/{$metier->id}", ['image' => self::IMAGE])->assertOk();

        $this->putJson("/api/v1/admin/metiers/{$metier->id}", ['image' => ''])->assertOk();

        $this->assertNull($metier->fresh()->getRawOriginal('image_url'));
    }
}
