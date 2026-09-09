<?php

namespace Tests\Feature\Messagerie;

use App\Models\Artisan;
use App\Models\Conversation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Autorisation des canaux privés Reverb.
 *
 * Sans ce contrôle, connaître un identifiant de conversation suffirait à
 * écouter les échanges d'autrui en temps réel.
 */
class CanalPriveTest extends TestCase
{
    use RefreshDatabase;

    private Conversation $conversation;

    private User $participant;

    protected function setUp(): void
    {
        parent::setUp();

        // Le pilote « null » utilisé par défaut en test autorise tous les
        // canaux : il ne permet pas d'éprouver le contrôle d'accès.
        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.key' => 'cle-de-test',
            'broadcasting.connections.reverb.secret' => 'secret-de-test',
            'broadcasting.connections.reverb.app_id' => '1',
        ]);

        // Les canaux ont été déclarés au démarrage sur le pilote « null ».
        // Changer de pilote ne les transporte pas : il faut les redéclarer
        // sur le nouveau, sinon tout accès est refusé faute de règle.
        require base_path('routes/channels.php');

        $this->participant = User::factory()->create(['role' => User::ROLE_CLIENT]);
        $artisan = Artisan::factory()->create();

        $this->conversation = Conversation::create(['type' => Conversation::TYPE_CLIENT_ARTISAN]);
        $this->conversation->participants()->attach([$this->participant->id, $artisan->utilisateur_id]);
    }

    private function autoriser(): TestResponse
    {
        return $this->postJson('/api/v1/diffusion/auth', [
            'socket_id' => '123.456',
            'channel_name' => 'private-conversation.'.$this->conversation->id,
        ]);
    }

    public function test_un_participant_obtient_la_signature_du_canal(): void
    {
        Sanctum::actingAs($this->participant);

        $this->autoriser()->assertOk()->assertJsonStructure(['auth']);
    }

    public function test_un_tiers_est_refuse(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->autoriser()->assertForbidden();
    }

    public function test_un_administrateur_n_ecoute_pas_les_conversations(): void
    {
        // L'administration traite les litiges par le back-office, pas en
        // écoutant les échanges en direct.
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->autoriser()->assertForbidden();
    }

    public function test_l_autorisation_exige_une_authentification(): void
    {
        $this->autoriser()->assertStatus(401);
    }

    public function test_un_canal_inexistant_est_refuse(): void
    {
        Sanctum::actingAs($this->participant);

        $this->postJson('/api/v1/diffusion/auth', [
            'socket_id' => '123.456',
            'channel_name' => 'private-conversation.999999',
        ])->assertForbidden();
    }
}
