<?php

namespace Tests\Feature\Messagerie;

use App\Events\MessageEnvoye;
use App\Models\Artisan;
use App\Models\Conversation;
use App\Models\DemandeDevis;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MessagerieTest extends TestCase
{
    use RefreshDatabase;

    private User $client;

    private Artisan $artisan;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = User::factory()->create(['role' => User::ROLE_CLIENT]);
        $this->artisan = Artisan::factory()->create(['statut_validation' => Artisan::VALIDATION_VALIDE]);
    }

    private function ouvrirConversation(): int
    {
        Sanctum::actingAs($this->client);

        return $this->postJson('/api/v1/conversations', [
            'interlocuteur_id' => $this->artisan->utilisateur_id,
        ])->assertCreated()->json('conversation.id');
    }

    // ------------------------------------------------------------------
    // Ouverture
    // ------------------------------------------------------------------

    public function test_une_conversation_s_ouvre_avec_un_interlocuteur(): void
    {
        $id = $this->ouvrirConversation();

        $this->assertDatabaseHas('conversation_participants', [
            'conversation_id' => $id,
            'user_id' => $this->client->id,
        ]);
        $this->assertDatabaseHas('conversation_participants', [
            'conversation_id' => $id,
            'user_id' => $this->artisan->utilisateur_id,
        ]);
    }

    public function test_rouvrir_une_conversation_ne_la_duplique_pas(): void
    {
        $premier = $this->ouvrirConversation();

        $second = $this->postJson('/api/v1/conversations', [
            'interlocuteur_id' => $this->artisan->utilisateur_id,
        ])->assertOk()->json('conversation.id');

        $this->assertSame($premier, $second);
        $this->assertDatabaseCount('conversations', 1);
    }

    public function test_une_demande_de_devis_n_a_qu_un_seul_fil(): void
    {
        $demande = DemandeDevis::factory()->create([
            'client_id' => $this->client->id,
            'artisan_id' => $this->artisan->id,
        ]);

        Sanctum::actingAs($this->client);
        $premier = $this->postJson('/api/v1/conversations', ['demande_id' => $demande->id])
            ->assertCreated()->json('conversation.id');

        // L'artisan ouvre le fil de son côté : il doit retomber sur le même.
        Sanctum::actingAs($this->artisan->utilisateur);
        $second = $this->postJson('/api/v1/conversations', ['demande_id' => $demande->id])
            ->assertOk()->json('conversation.id');

        $this->assertSame($premier, $second);
    }

    public function test_on_ne_peut_pas_ouvrir_le_fil_d_une_demande_qui_ne_nous_concerne_pas(): void
    {
        $demande = DemandeDevis::factory()->create([
            'client_id' => $this->client->id,
            'artisan_id' => $this->artisan->id,
        ]);

        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/v1/conversations', ['demande_id' => $demande->id])
            ->assertForbidden();
    }

    // ------------------------------------------------------------------
    // Échanges
    // ------------------------------------------------------------------

    public function test_un_message_est_envoye_et_diffuse(): void
    {
        Event::fake([MessageEnvoye::class]);

        $id = $this->ouvrirConversation();

        $this->postJson("/api/v1/conversations/{$id}/messages", [
            'contenu' => 'Bonjour, êtes-vous disponible cette semaine ?',
        ])
            ->assertCreated()
            ->assertJsonPath('donnees.contenu', 'Bonjour, êtes-vous disponible cette semaine ?')
            ->assertJsonPath('donnees.deMoi', true);

        // La diffusion temps réel est ce qui tient le délai du §7.2.
        Event::assertDispatched(MessageEnvoye::class);
    }

    public function test_le_destinataire_voit_le_message_comme_n_etant_pas_de_lui(): void
    {
        $id = $this->ouvrirConversation();

        $this->postJson("/api/v1/conversations/{$id}/messages", ['contenu' => 'Bonjour.']);

        Sanctum::actingAs($this->artisan->utilisateur);

        $this->getJson("/api/v1/conversations/{$id}/messages")
            ->assertOk()
            ->assertJsonPath('data.0.deMoi', false)
            ->assertJsonPath('data.0.contenu', 'Bonjour.');
    }

    public function test_un_tiers_ne_peut_ni_lire_ni_ecrire(): void
    {
        $id = $this->ouvrirConversation();

        Sanctum::actingAs(User::factory()->create());

        $this->getJson("/api/v1/conversations/{$id}/messages")->assertForbidden();
        $this->postJson("/api/v1/conversations/{$id}/messages", ['contenu' => 'Intrusion'])
            ->assertForbidden();
    }

    public function test_un_message_vide_est_refuse(): void
    {
        $id = $this->ouvrirConversation();

        $this->postJson("/api/v1/conversations/{$id}/messages", [])->assertStatus(422);
    }

    // ------------------------------------------------------------------
    // Non-lus
    // ------------------------------------------------------------------

    public function test_le_compteur_de_non_lus_suit_la_lecture(): void
    {
        $id = $this->ouvrirConversation();

        // L'artisan écrit deux messages.
        Sanctum::actingAs($this->artisan->utilisateur);
        $this->postJson("/api/v1/conversations/{$id}/messages", ['contenu' => 'Bonjour.']);
        $this->postJson("/api/v1/conversations/{$id}/messages", ['contenu' => 'Je suis disponible jeudi.']);

        Sanctum::actingAs($this->client);
        $this->getJson('/api/v1/conversations')
            ->assertOk()
            ->assertJsonPath('data.0.nonLus', 2)
            ->assertJsonPath('data.0.dernierMessage', 'Je suis disponible jeudi.');

        $this->postJson("/api/v1/conversations/{$id}/lu")->assertOk();

        $this->getJson('/api/v1/conversations')->assertJsonPath('data.0.nonLus', 0);
    }

    public function test_une_reponse_dans_la_meme_seconde_est_bien_comptee(): void
    {
        $id = $this->ouvrirConversation();

        // Le client écrit : cela cale son « lu_jusqu_a » sur cet horodatage.
        $this->postJson("/api/v1/conversations/{$id}/messages", ['contenu' => 'Bonjour, êtes-vous libre ?']);

        // L'artisan répond dans la même seconde.
        Sanctum::actingAs($this->artisan->utilisateur);
        $this->postJson("/api/v1/conversations/{$id}/messages", ['contenu' => 'Oui, jeudi matin.']);

        Sanctum::actingAs($this->client);
        $this->getJson('/api/v1/conversations')
            ->assertOk()
            ->assertJsonPath('data.0.nonLus', 1);
    }

    public function test_ses_propres_messages_ne_comptent_pas_comme_non_lus(): void
    {
        $id = $this->ouvrirConversation();

        $this->postJson("/api/v1/conversations/{$id}/messages", ['contenu' => 'Bonjour.']);

        $this->getJson('/api/v1/conversations')->assertJsonPath('data.0.nonLus', 0);
    }

    public function test_la_liste_montre_l_interlocuteur_et_non_soi_meme(): void
    {
        $this->ouvrirConversation();

        $this->getJson('/api/v1/conversations')
            ->assertOk()
            ->assertJsonPath('data.0.interlocuteur.id', $this->artisan->utilisateur_id);
    }

    // ------------------------------------------------------------------
    // Support
    // ------------------------------------------------------------------

    public function test_le_fil_de_support_s_ouvre_et_se_reutilise(): void
    {
        User::factory()->admin()->create();

        Sanctum::actingAs($this->client);

        $premier = $this->getJson('/api/v1/conversations/support')
            ->assertOk()
            ->assertJsonPath('conversation.estSupport', true)
            ->json('conversation.id');

        $second = $this->getJson('/api/v1/conversations/support')->json('conversation.id');

        $this->assertSame($premier, $second);
        $this->assertSame(1, Conversation::where('type', Conversation::TYPE_SUPPORT)->count());
    }

    public function test_la_messagerie_exige_une_authentification(): void
    {
        $this->getJson('/api/v1/conversations')->assertStatus(401);
    }
}
