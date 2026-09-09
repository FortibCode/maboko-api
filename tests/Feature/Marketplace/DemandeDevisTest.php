<?php

namespace Tests\Feature\Marketplace;

use App\Models\Artisan;
use App\Models\DemandeDevis;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DemandeDevisTest extends TestCase
{
    use RefreshDatabase;

    private User $client;

    private Artisan $artisan;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = User::factory()->create(['role' => User::ROLE_CLIENT]);
        $this->artisan = Artisan::factory()->create([
            'statut_validation' => Artisan::VALIDATION_VALIDE,
        ]);
    }

    private function corpsDemande(array $remplacements = []): array
    {
        return array_merge([
            'artisan_id' => $this->artisan->id,
            'titre' => 'Fuite sous l évier',
            'description' => 'Fuite sous l évier de la cuisine depuis deux jours, à Bacongo.',
            'adresse' => 'Rue Mbochis, Bacongo, Brazzaville',
            'budget_estime' => 25000,
        ], $remplacements);
    }

    // ------------------------------------------------------------------
    // Parcours nominal
    // ------------------------------------------------------------------

    public function test_le_parcours_complet_du_devis_a_la_note(): void
    {
        // 1. Le client envoie sa demande
        Sanctum::actingAs($this->client);
        $reponse = $this->postJson('/api/v1/demandes', $this->corpsDemande())->assertCreated();
        $id = $reponse->json('demande.id');

        $this->assertDatabaseHas('demandes_devis', [
            'id' => $id,
            'client_id' => $this->client->id,
            'artisan_id' => $this->artisan->id,
            'statut' => DemandeDevis::STATUT_EN_ATTENTE,
        ]);

        // 2. L'artisan la reçoit dans ses missions et l'accepte
        Sanctum::actingAs($this->artisan->utilisateur);
        $this->getJson('/api/v1/demandes')
            ->assertOk()
            ->assertJsonPath('data.0.id', $id);

        $this->postJson("/api/v1/demandes/{$id}/accepter", ['montant_propose' => 30000])
            ->assertOk()
            ->assertJsonPath('demande.statut', DemandeDevis::STATUT_ACCEPTEE)
            ->assertJsonPath('demande.montantPropose', 30000);

        // 3. Il démarre puis termine l'intervention
        $this->postJson("/api/v1/demandes/{$id}/demarrer")
            ->assertOk()
            ->assertJsonPath('demande.statut', DemandeDevis::STATUT_EN_COURS);

        $this->postJson("/api/v1/demandes/{$id}/terminer", ['montant_final' => 28000])
            ->assertOk()
            ->assertJsonPath('demande.statut', DemandeDevis::STATUT_TERMINEE);

        // 4. Le client note l'intervention
        Sanctum::actingAs($this->client);
        $this->postJson("/api/v1/demandes/{$id}/avis", [
            'note' => 5,
            'commentaire' => 'Travail rapide et soigné.',
        ])->assertCreated();

        // 5. La note remonte sur la fiche de l'artisan
        $this->artisan->refresh();
        $this->assertSame(1, $this->artisan->nb_avis);
        $this->assertSame('5.00', $this->artisan->note_moyenne);
        $this->assertSame(1, $this->artisan->nb_missions_terminees);
        $this->assertGreaterThan(0, (float) $this->artisan->score_classement);
    }

    public function test_les_photos_sont_stockees_comme_fichiers(): void
    {
        Storage::fake('public');
        Sanctum::actingAs($this->client);

        $png = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

        $this->postJson('/api/v1/demandes', $this->corpsDemande(['photos' => [$png, $png]]))
            ->assertCreated()
            ->assertJsonCount(2, 'demande.photos');

        $this->assertCount(2, Storage::disk('public')->allFiles('demandes'));
    }

    // ------------------------------------------------------------------
    // Autorisations
    // ------------------------------------------------------------------

    public function test_un_artisan_ne_peut_pas_emettre_une_demande(): void
    {
        Sanctum::actingAs($this->artisan->utilisateur);

        $this->postJson('/api/v1/demandes', $this->corpsDemande())->assertForbidden();
    }

    public function test_un_tiers_ne_peut_pas_consulter_une_demande(): void
    {
        $demande = DemandeDevis::factory()->create([
            'client_id' => $this->client->id,
            'artisan_id' => $this->artisan->id,
        ]);

        Sanctum::actingAs(User::factory()->create());

        $this->getJson("/api/v1/demandes/{$demande->id}")->assertForbidden();
    }

    public function test_un_autre_artisan_ne_peut_pas_accepter_la_mission(): void
    {
        $demande = DemandeDevis::factory()->create([
            'client_id' => $this->client->id,
            'artisan_id' => $this->artisan->id,
        ]);

        $intrus = Artisan::factory()->create(['statut_validation' => Artisan::VALIDATION_VALIDE]);
        Sanctum::actingAs($intrus->utilisateur);

        $this->postJson("/api/v1/demandes/{$demande->id}/accepter", ['montant_propose' => 1000])
            ->assertForbidden();

        $this->assertSame(DemandeDevis::STATUT_EN_ATTENTE, $demande->fresh()->statut);
    }

    public function test_le_client_ne_peut_pas_accepter_sa_propre_demande(): void
    {
        $demande = DemandeDevis::factory()->create([
            'client_id' => $this->client->id,
            'artisan_id' => $this->artisan->id,
        ]);

        Sanctum::actingAs($this->client);

        $this->postJson("/api/v1/demandes/{$demande->id}/accepter", ['montant_propose' => 1000])
            ->assertForbidden();
    }

    public function test_une_demande_deja_refusee_ne_peut_plus_etre_acceptee(): void
    {
        $demande = DemandeDevis::factory()->statut(DemandeDevis::STATUT_REFUSEE)->create([
            'client_id' => $this->client->id,
            'artisan_id' => $this->artisan->id,
        ]);

        Sanctum::actingAs($this->artisan->utilisateur);

        $this->postJson("/api/v1/demandes/{$demande->id}/accepter", ['montant_propose' => 1000])
            ->assertForbidden();
    }

    public function test_on_ne_peut_pas_s_adresser_une_demande_a_soi_meme(): void
    {
        Sanctum::actingAs($this->artisan->utilisateur);

        // Le compte est artisan : la policy le bloque avant même ce contrôle.
        $this->postJson('/api/v1/demandes', $this->corpsDemande())->assertForbidden();
    }

    public function test_une_demande_vers_un_artisan_non_valide_est_refusee(): void
    {
        $enAttente = Artisan::factory()->create(['statut_validation' => Artisan::VALIDATION_EN_ATTENTE]);

        Sanctum::actingAs($this->client);

        $this->postJson('/api/v1/demandes', $this->corpsDemande(['artisan_id' => $enAttente->id]))
            ->assertStatus(422);
    }

    public function test_le_client_annule_tant_que_rien_n_est_termine(): void
    {
        $demande = DemandeDevis::factory()->statut(DemandeDevis::STATUT_ACCEPTEE)->create([
            'client_id' => $this->client->id,
            'artisan_id' => $this->artisan->id,
        ]);

        Sanctum::actingAs($this->client);

        $this->postJson("/api/v1/demandes/{$demande->id}/annuler")
            ->assertOk()
            ->assertJsonPath('demande.statut', DemandeDevis::STATUT_ANNULEE);
    }

    public function test_une_demande_terminee_ne_peut_plus_etre_annulee(): void
    {
        $demande = DemandeDevis::factory()->statut(DemandeDevis::STATUT_TERMINEE)->create([
            'client_id' => $this->client->id,
            'artisan_id' => $this->artisan->id,
        ]);

        Sanctum::actingAs($this->client);

        $this->postJson("/api/v1/demandes/{$demande->id}/annuler")->assertForbidden();
    }

    // ------------------------------------------------------------------
    // Validation
    // ------------------------------------------------------------------

    public function test_une_description_trop_courte_est_refusee(): void
    {
        Sanctum::actingAs($this->client);

        $this->postJson('/api/v1/demandes', $this->corpsDemande(['description' => 'Fuite']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('description');
    }

    public function test_une_date_passee_est_refusee(): void
    {
        Sanctum::actingAs($this->client);

        $this->postJson('/api/v1/demandes', $this->corpsDemande([
            'date_souhaitee' => now()->subWeek()->toDateString(),
        ]))->assertStatus(422)->assertJsonValidationErrors('date_souhaitee');
    }
}
