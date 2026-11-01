<?php

namespace Tests\Feature\Marketplace;

use App\Models\Artisan;
use App\Models\Avis;
use App\Models\DemandeDevis;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Écran « Mes avis » du client : l'historique de ce qu'il a évalué.
 */
class MesAvisTest extends TestCase
{
    use RefreshDatabase;

    public function test_le_client_retrouve_ses_avis_avec_le_nom_de_l_artisan(): void
    {
        $client = User::factory()->create(['role' => User::ROLE_CLIENT]);
        $artisan = Artisan::factory()->create();
        $demande = DemandeDevis::factory()->statut(DemandeDevis::STATUT_TERMINEE)->create([
            'client_id' => $client->id,
            'artisan_id' => $artisan->id,
        ]);

        Avis::create([
            'auteur_id' => $client->id,
            'artisan_id' => $artisan->id,
            'demande_devis_id' => $demande->id,
            'note' => 4,
            'commentaire' => 'Travail propre.',
        ]);

        // L'avis d'un autre client ne doit pas apparaître.
        $autre = DemandeDevis::factory()->statut(DemandeDevis::STATUT_TERMINEE)->create(['artisan_id' => $artisan->id]);
        Avis::create([
            'auteur_id' => $autre->client_id,
            'artisan_id' => $artisan->id,
            'demande_devis_id' => $autre->id,
            'note' => 2,
        ]);

        Sanctum::actingAs($client);

        $this->getJson('/api/v1/compte/avis')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.note', 4)
            ->assertJsonPath('data.0.artisan.id', $artisan->id)
            ->assertJsonPath(
                'data.0.artisan.utilisateur.nomComplet',
                trim($artisan->utilisateur->prenom.' '.$artisan->utilisateur->nom),
            );
    }
}
