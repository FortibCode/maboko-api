<?php

namespace Tests\Feature\Fil;

use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Règles d'accès et de stockage des publications.
 * Le comportement du fil lui-même est couvert par FilActualiteTest.
 */
class PublicationTest extends TestCase
{
    use RefreshDatabase;

    public function test_une_publication_est_attribuee_a_l_utilisateur_connecte(): void
    {
        $auteur = User::factory()->artisan()->create();
        $victime = User::factory()->artisan()->create();

        Sanctum::actingAs($auteur);

        $this->postJson('/api/v1/posts', [
            // Tentative d'usurpation : l'identifiant fourni doit être ignoré.
            'artisan_id' => $victime->id,
            'artisan_category' => 'Plombier',
            'image_url' => 'https://exemple.cg/photo.jpg',
            'description' => 'Installation sanitaire.',
        ])->assertCreated();

        $this->assertSame($auteur->id, Post::first()->artisan_id);
    }

    public function test_une_image_en_base64_est_stockee_comme_fichier(): void
    {
        Storage::fake('public');

        Sanctum::actingAs(User::factory()->artisan()->create());

        // Un PNG transparent de 1x1 pixel.
        $png = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

        $this->postJson('/api/v1/posts', [
            'artisan_category' => 'Peintre',
            'image_url' => $png,
            'description' => 'Facade repeinte a Moungali.',
        ])->assertCreated();

        $url = Post::first()->image_url;

        $this->assertStringNotContainsString('base64', $url, "L'image base64 a été stockée telle quelle en base.");
        $this->assertCount(1, Storage::disk('public')->allFiles('publications'));
    }

    public function test_un_format_de_fichier_non_supporte_est_refuse(): void
    {
        Storage::fake('public');
        Sanctum::actingAs(User::factory()->artisan()->create());

        $this->postJson('/api/v1/posts', [
            'artisan_category' => 'Peintre',
            'image_url' => 'data:application/pdf;base64,JVBERi0xLjQK',
            'description' => 'Devis en PDF.',
        ])->assertStatus(422);
    }

    public function test_une_publication_sans_image_est_refusee(): void
    {
        Sanctum::actingAs(User::factory()->artisan()->create());

        $this->postJson('/api/v1/posts', [
            'artisan_category' => 'Peintre',
            'description' => 'Une réalisation sans photo.',
        ])->assertStatus(422);
    }

    public function test_le_fil_est_reserve_aux_utilisateurs_connectes(): void
    {
        $this->getJson('/api/v1/posts')->assertStatus(401);
        $this->getJson('/api/v1/stories')->assertStatus(401);
    }
}
