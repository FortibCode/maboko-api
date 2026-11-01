<?php

namespace Tests\Feature\Fil;

use App\Models\Story;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Écran « Mes stories » de l'artisan : ses stories, qui les a vues, retrait.
 */
class MesStoriesTest extends TestCase
{
    use RefreshDatabase;

    private User $artisan;

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan = User::factory()->create(['role' => User::ROLE_ARTISAN]);
    }

    private function story(array $attributs = []): Story
    {
        return Story::create($attributs + [
            'artisan_id' => $this->artisan->id,
            'media_url' => 'stories/atelier.jpg',
            'expire_at' => now()->addHours(24),
        ]);
    }

    public function test_l_artisan_voit_ses_stories_visibles_et_leurs_vues(): void
    {
        $this->story(['vues_count' => 3]);
        $this->story(['expire_at' => now()->subMinute()]);
        Story::create([
            'artisan_id' => User::factory()->create(['role' => User::ROLE_ARTISAN])->id,
            'media_url' => 'stories/autre.jpg',
            'expire_at' => now()->addHours(24),
        ]);

        Sanctum::actingAs($this->artisan);

        $this->getJson('/api/v1/stories/me')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.vues_count', 3);
    }

    public function test_une_personne_ne_compte_qu_une_fois(): void
    {
        $story = $this->story();
        $client = User::factory()->create(['role' => User::ROLE_CLIENT]);

        Sanctum::actingAs($client);
        $this->postJson("/api/v1/stories/{$story->id}/vue")->assertOk();
        $this->postJson("/api/v1/stories/{$story->id}/vue")->assertOk()->assertJsonPath('vuesCount', 1);

        Sanctum::actingAs($this->artisan);
        $this->postJson("/api/v1/stories/{$story->id}/vue")->assertOk()->assertJsonPath('vuesCount', 1);

        $this->getJson("/api/v1/stories/{$story->id}/vues")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $client->id);
    }

    public function test_seul_l_auteur_voit_les_vues_et_supprime(): void
    {
        $story = $this->story();

        Sanctum::actingAs(User::factory()->create(['role' => User::ROLE_ARTISAN]));

        $this->getJson("/api/v1/stories/{$story->id}/vues")->assertForbidden();
        $this->deleteJson("/api/v1/stories/{$story->id}")->assertForbidden();
        $this->assertDatabaseHas('stories', ['id' => $story->id]);
    }

    public function test_l_auteur_supprime_sa_story(): void
    {
        $story = $this->story();

        Sanctum::actingAs($this->artisan);

        $this->deleteJson("/api/v1/stories/{$story->id}")->assertOk();
        $this->assertDatabaseMissing('stories', ['id' => $story->id]);
    }
}
