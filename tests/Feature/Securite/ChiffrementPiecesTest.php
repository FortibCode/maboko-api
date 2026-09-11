<?php

namespace Tests\Feature\Securite;

use App\Models\User;
use App\Models\VerificationIdentite;
use App\Services\MediaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Chiffrement au repos des pièces d'identité (§7.1).
 */
class ChiffrementPiecesTest extends TestCase
{
    use RefreshDatabase;

    private const PNG = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_le_fichier_stocke_n_est_pas_lisible_en_clair(): void
    {
        $chemin = app(MediaService::class)->enregistrerPiecePrivee(self::PNG, 'identites');

        $surDisque = Storage::disk('local')->get($chemin);
        $original = base64_decode(explode(',', self::PNG)[1], true);

        // Une copie de la sauvegarde ou un accès au disque ne doit rien livrer.
        $this->assertNotSame($original, $surDisque);
        $this->assertStringNotContainsString('PNG', (string) $surDisque);
    }

    public function test_le_fichier_se_relit_a_l_identique(): void
    {
        $service = app(MediaService::class);

        $chemin = $service->enregistrerPiecePrivee(self::PNG, 'identites');
        $relu = $service->lirePiecePrivee($chemin);

        $this->assertSame(base64_decode(explode(',', self::PNG)[1], true), $relu);
    }

    public function test_un_fichier_absent_ne_leve_pas_d_exception(): void
    {
        $this->assertNull(app(MediaService::class)->lirePiecePrivee('identites/inexistant.jpg.chiffre'));
    }

    public function test_un_fichier_corrompu_est_signale_sans_planter(): void
    {
        Storage::disk('local')->put('identites/corrompu.jpg.chiffre', 'contenu illisible');

        // Arrive après une rotation de clé sans reprise des fichiers.
        $this->assertNull(app(MediaService::class)->lirePiecePrivee('identites/corrompu.jpg.chiffre'));
    }

    public function test_la_piece_dechiffree_n_est_jamais_mise_en_cache(): void
    {
        $chemin = app(MediaService::class)->enregistrerPiecePrivee(self::PNG, 'identites');

        $verification = VerificationIdentite::create([
            'user_id' => User::factory()->create()->id,
            'type_piece' => 'cni',
            'chemin_recto' => $chemin,
            'statut' => 'en_attente',
        ]);

        Sanctum::actingAs(User::factory()->admin()->create());

        $lien = $this->getJson('/api/v1/admin/verifications')->json('data.0.pieces.recto');
        $reponse = $this->get($lien);

        $reponse->assertOk();
        $reponse->assertHeader('Cache-Control', 'no-store, private');
        $reponse->assertHeader('Content-Type', 'image/png');
    }
}
