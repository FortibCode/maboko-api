<?php

namespace Tests\Feature\Marketplace;

use App\Models\MoyenPaiement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Moyens de paiement enregistres (§5.1.10).
 *
 * Seuls l'operateur et le numero sont conserves : le Mobile Money confirme
 * chaque paiement par une invite envoyee au telephone du titulaire.
 */
class MoyenPaiementTest extends TestCase
{
    use RefreshDatabase;

    private function moyen(array $remplacements = []): array
    {
        return array_merge([
            'operateur' => 'airtel',
            'telephone' => '+242064290111',
            'libelle' => 'Mon numero',
        ], $remplacements);
    }

    public function test_un_utilisateur_enregistre_un_moyen_de_paiement(): void
    {
        $utilisateur = User::factory()->create();
        Sanctum::actingAs($utilisateur);

        $this->postJson('/api/v1/moyens-paiement', $this->moyen())
            ->assertCreated()
            ->assertJsonPath('operateur', 'airtel')
            ->assertJsonPath('parDefaut', true);

        $this->assertDatabaseHas('moyens_paiement', [
            'utilisateur_id' => $utilisateur->id,
            'telephone' => '+242064290111',
        ]);
    }

    public function test_le_numero_est_masque_a_l_affichage(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/v1/moyens-paiement', $this->moyen())
            ->assertCreated()
            ->assertJsonPath('telephoneMasque', '•••• 0111');
    }

    public function test_le_premier_moyen_devient_celui_par_defaut(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/v1/moyens-paiement', $this->moyen())
            ->assertCreated()
            ->assertJsonPath('parDefaut', true);

        $this->postJson('/api/v1/moyens-paiement', $this->moyen([
            'operateur' => 'mtn',
            'telephone' => '+242054290222',
        ]))->assertCreated()->assertJsonPath('parDefaut', false);
    }

    public function test_un_seul_moyen_reste_par_defaut(): void
    {
        $utilisateur = User::factory()->create();
        Sanctum::actingAs($utilisateur);

        $this->postJson('/api/v1/moyens-paiement', $this->moyen())->assertCreated();
        $second = $this->postJson('/api/v1/moyens-paiement', $this->moyen([
            'operateur' => 'mtn',
            'telephone' => '+242054290222',
        ]))->json('id');

        $this->postJson("/api/v1/moyens-paiement/{$second}/defaut")
            ->assertOk()
            ->assertJsonPath('parDefaut', true);

        $this->assertSame(
            1,
            MoyenPaiement::where('utilisateur_id', $utilisateur->id)->where('par_defaut', true)->count(),
        );
    }

    public function test_un_numero_hors_format_congolais_est_refuse(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/v1/moyens-paiement', $this->moyen(['telephone' => '+33612345678']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('telephone');
    }

    public function test_un_operateur_inconnu_est_refuse(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/v1/moyens-paiement', $this->moyen(['operateur' => 'orange']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('operateur');
    }

    public function test_le_meme_numero_n_est_pas_enregistre_deux_fois(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/v1/moyens-paiement', $this->moyen())->assertCreated();
        $this->postJson('/api/v1/moyens-paiement', $this->moyen())->assertStatus(422);
    }

    public function test_retirer_le_moyen_par_defaut_en_designe_un_autre(): void
    {
        $utilisateur = User::factory()->create();
        Sanctum::actingAs($utilisateur);

        $premier = $this->postJson('/api/v1/moyens-paiement', $this->moyen())->json('id');
        $this->postJson('/api/v1/moyens-paiement', $this->moyen([
            'operateur' => 'mtn',
            'telephone' => '+242054290222',
        ]))->assertCreated();

        $this->deleteJson("/api/v1/moyens-paiement/{$premier}")->assertOk();

        $this->assertSame(
            1,
            MoyenPaiement::where('utilisateur_id', $utilisateur->id)->where('par_defaut', true)->count(),
        );
    }

    public function test_un_utilisateur_ne_voit_pas_les_moyens_d_un_autre(): void
    {
        $autre = User::factory()->create();
        MoyenPaiement::create([
            'utilisateur_id' => $autre->id,
            'operateur' => 'airtel',
            'telephone' => '+242064290999',
            'par_defaut' => true,
        ]);

        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/v1/moyens-paiement')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_un_utilisateur_ne_peut_pas_supprimer_le_moyen_d_un_autre(): void
    {
        $autre = User::factory()->create();
        $moyen = MoyenPaiement::create([
            'utilisateur_id' => $autre->id,
            'operateur' => 'airtel',
            'telephone' => '+242064290999',
            'par_defaut' => true,
        ]);

        Sanctum::actingAs(User::factory()->create());

        $this->deleteJson("/api/v1/moyens-paiement/{$moyen->id}")->assertNotFound();
        $this->assertNotNull($moyen->fresh());
    }

    public function test_la_liste_exige_une_authentification(): void
    {
        $this->getJson('/api/v1/moyens-paiement')->assertUnauthorized();
    }
}
