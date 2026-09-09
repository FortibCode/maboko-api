<?php

namespace Tests\Feature\Confiance;

use App\Models\User;
use App\Models\VerificationIdentite;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class VerificationIdentiteTest extends TestCase
{
    use RefreshDatabase;

    /** Un PNG transparent de 1x1 pixel. */
    private const IMAGE = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        // Le disque public est simulé lui aussi, pour pouvoir affirmer
        // qu'aucune pièce n'y atterrit.
        Storage::fake('public');
        Sanctum::actingAs(User::factory()->artisan()->create());
    }

    private function deposer(array $remplacements = []): TestResponse
    {
        return $this->postJson('/api/v1/verification-identite', array_merge([
            'type_piece' => 'cni',
            'numero_piece' => 'CG-1234567',
            'recto' => self::IMAGE,
            'verso' => self::IMAGE,
        ], $remplacements));
    }

    public function test_sans_demande_le_statut_est_absent(): void
    {
        $this->getJson('/api/v1/verification-identite')
            ->assertOk()
            ->assertJsonPath('statut', 'absente');
    }

    public function test_les_pieces_sont_stockees_sur_le_disque_prive(): void
    {
        $this->deposer()->assertCreated()->assertJsonPath('statut', 'en_attente');

        // Le disque privé, pas le disque public : ces documents ne doivent
        // jamais être servis par une URL devinable (§7.1).
        $this->assertCount(2, Storage::disk('local')->allFiles('identites'));
        $this->assertCount(0, Storage::disk('public')->allFiles());
    }

    public function test_les_chemins_des_pieces_ne_sortent_jamais_de_l_api(): void
    {
        $this->deposer()->assertCreated();

        $reponse = $this->getJson('/api/v1/verification-identite')->assertOk();

        foreach (['chemin_recto', 'chemin_verso', 'chemin_selfie', 'numero_piece'] as $champ) {
            $this->assertStringNotContainsString($champ, $reponse->content());
        }
        $this->assertStringNotContainsString('identites/', $reponse->content());
    }

    public function test_une_seconde_demande_est_refusee_tant_que_la_premiere_est_en_cours(): void
    {
        $this->deposer()->assertCreated();

        $this->deposer()->assertStatus(422);

        $this->assertSame(1, VerificationIdentite::count());
    }

    public function test_une_identite_deja_verifiee_ne_se_redepose_pas(): void
    {
        $this->deposer()->assertCreated();
        VerificationIdentite::first()->update(['statut' => 'valide', 'verifie_at' => now()]);

        $this->deposer()->assertStatus(422);

        $this->getJson('/api/v1/verification-identite')->assertJsonPath('statut', 'valide');
    }

    public function test_apres_un_rejet_de_nouvelles_pieces_sont_acceptees(): void
    {
        $this->deposer()->assertCreated();
        VerificationIdentite::first()->update([
            'statut' => 'rejete',
            'motif_rejet' => 'Photo illisible.',
        ]);

        $this->getJson('/api/v1/verification-identite')
            ->assertJsonPath('statut', 'rejete')
            ->assertJsonPath('motifRejet', 'Photo illisible.');

        $this->deposer()->assertCreated();
        $this->assertSame(2, VerificationIdentite::count());
    }

    public function test_un_type_de_piece_inconnu_est_refuse(): void
    {
        $this->deposer(['type_piece' => 'carte_de_fidelite'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('type_piece');
    }

    public function test_le_recto_est_obligatoire(): void
    {
        $this->postJson('/api/v1/verification-identite', ['type_piece' => 'cni'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('recto');
    }

    public function test_un_fichier_non_image_est_refuse(): void
    {
        $this->deposer(['recto' => 'data:application/pdf;base64,JVBERi0xLjQK'])
            ->assertStatus(422);

        $this->assertSame(0, VerificationIdentite::count());
    }

    public function test_la_verification_exige_une_authentification(): void
    {
        app('auth')->forgetGuards();

        $this->getJson('/api/v1/verification-identite')->assertStatus(401);
        $this->postJson('/api/v1/verification-identite', [])->assertStatus(401);
    }
}
