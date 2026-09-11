<?php

namespace Tests\Feature\Marketplace;

use App\Models\Artisan;
use App\Models\Avis;
use App\Models\DemandeDevis;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TableauDeBordTest extends TestCase
{
    use RefreshDatabase;

    public function test_le_client_voit_ses_compteurs_reels(): void
    {
        $client = User::factory()->create(['role' => User::ROLE_CLIENT]);
        $artisan = Artisan::factory()->create(['statut_validation' => Artisan::VALIDATION_VALIDE]);

        DemandeDevis::factory()->count(2)->create([
            'client_id' => $client->id, 'artisan_id' => $artisan->id,
        ]);
        DemandeDevis::factory()->statut(DemandeDevis::STATUT_EN_COURS)->create([
            'client_id' => $client->id, 'artisan_id' => $artisan->id,
        ]);
        $terminee = DemandeDevis::factory()->statut(DemandeDevis::STATUT_TERMINEE)->create([
            'client_id' => $client->id, 'artisan_id' => $artisan->id, 'montant_final' => 75000,
        ]);

        Avis::create([
            'auteur_id' => $client->id,
            'artisan_id' => $artisan->id,
            'demande_devis_id' => $terminee->id,
            'note' => 4,
        ]);

        Sanctum::actingAs($client);

        $this->getJson('/api/v1/tableau-de-bord')
            ->assertOk()
            ->assertJsonPath('role', 'client')
            ->assertJsonPath('demandes.total', 4)
            ->assertJsonPath('demandes.enAttente', 2)
            ->assertJsonPath('demandes.enCours', 1)
            ->assertJsonPath('demandes.terminees', 1)
            ->assertJsonPath('avisDeposes', 1)
            ->assertJsonPath('montantEngage', 75000)
            ->assertJsonCount(4, 'dernieresDemandes');
    }

    public function test_l_artisan_voit_ses_missions_et_ses_revenus(): void
    {
        $artisan = Artisan::factory()->create(['statut_validation' => Artisan::VALIDATION_VALIDE]);
        $client = User::factory()->create(['role' => User::ROLE_CLIENT]);

        DemandeDevis::factory()->create(['client_id' => $client->id, 'artisan_id' => $artisan->id]);
        DemandeDevis::factory()->statut(DemandeDevis::STATUT_ACCEPTEE)->create([
            'client_id' => $client->id, 'artisan_id' => $artisan->id,
        ]);
        DemandeDevis::factory()->statut(DemandeDevis::STATUT_TERMINEE)->create([
            'client_id' => $client->id, 'artisan_id' => $artisan->id,
            'montant_final' => 120000, 'terminee_at' => now(),
        ]);
        // Terminée le mois dernier : compte dans le total, pas dans le mois courant.
        DemandeDevis::factory()->statut(DemandeDevis::STATUT_TERMINEE)->create([
            'client_id' => $client->id, 'artisan_id' => $artisan->id,
            'montant_final' => 80000, 'terminee_at' => now()->subMonths(2),
        ]);

        Sanctum::actingAs($artisan->utilisateur);

        $this->getJson('/api/v1/tableau-de-bord')
            ->assertOk()
            ->assertJsonPath('role', 'artisan')
            ->assertJsonPath('ficheManquante', false)
            ->assertJsonPath('missions.total', 4)
            ->assertJsonPath('missions.enAttente', 1)
            ->assertJsonPath('missions.enCours', 1)
            ->assertJsonPath('missions.terminees', 2)
            ->assertJsonPath('revenus.total', 200000)
            ->assertJsonPath('revenus.moisCourant', 120000)
            ->assertJsonPath('abonnement.slug', 'gratuit')
            // Seules les missions à traiter remontent sur le tableau de bord.
            ->assertJsonCount(1, 'dernieresDemandes');
    }

    public function test_un_compte_artisan_sans_fiche_est_invite_a_la_completer(): void
    {
        $utilisateur = User::factory()->artisan()->create();

        Sanctum::actingAs($utilisateur);

        $this->getJson('/api/v1/tableau-de-bord')
            ->assertOk()
            ->assertJsonPath('ficheManquante', true);
    }

    public function test_le_tableau_de_bord_exige_une_authentification(): void
    {
        $this->getJson('/api/v1/tableau-de-bord')->assertStatus(401);
    }

    public function test_les_favoris_se_basculent(): void
    {
        $client = User::factory()->create(['role' => User::ROLE_CLIENT]);
        $artisan = Artisan::factory()->create(['statut_validation' => Artisan::VALIDATION_VALIDE]);

        Sanctum::actingAs($client);

        $this->postJson("/api/v1/favoris/{$artisan->id}")
            ->assertCreated()
            ->assertJsonPath('favori', true);

        $this->getJson('/api/v1/favoris')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/tableau-de-bord')->assertJsonPath('favoris', 1);

        // Un second appel retire l'artisan des favoris.
        $this->postJson("/api/v1/favoris/{$artisan->id}")
            ->assertOk()
            ->assertJsonPath('favori', false);

        $this->getJson('/api/v1/favoris')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_le_tableau_de_bord_expose_douze_mois_de_revenus(): void
    {
        $artisan = Artisan::factory()->create();
        Sanctum::actingAs($artisan->utilisateur);

        $reponse = $this->getJson('/api/v1/tableau-de-bord')->assertOk();

        $serie = $reponse->json('revenus.parMois');

        // Douze mois glissants, meme sans activite : une courbe qui sauterait
        // les mois vides ferait lire une activite continue inexistante.
        $this->assertCount(12, $serie);
        $this->assertSame(now()->format('Y-m'), $serie[11]['mois']);
        $this->assertSame(now()->copy()->subMonths(11)->format('Y-m'), $serie[0]['mois']);
    }

    public function test_un_mois_sans_mission_vaut_zero(): void
    {
        $artisan = Artisan::factory()->create();
        Sanctum::actingAs($artisan->utilisateur);

        $serie = $this->getJson('/api/v1/tableau-de-bord')->json('revenus.parMois');

        // Le decodage JSON ramene 0.0 a un entier : on compare la valeur,
        // pas le type.
        foreach ($serie as $mois) {
            $this->assertEquals(0, $mois['total']);
        }
    }
}
