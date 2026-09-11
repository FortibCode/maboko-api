<?php

namespace Tests\Feature\Securite;

use App\Models\Appareil;
use App\Models\Artisan;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Plan;
use App\Models\Post;
use App\Models\Transaction;
use App\Models\User;
use App\Models\VerificationIdentite;
use App\Services\MediaService;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Suppression du compte à la demande de son titulaire.
 *
 * Deux exigences s'opposent : le droit à l'effacement des données
 * personnelles, et l'obligation de conserver les pièces comptables.
 */
class SuppressionCompteTest extends TestCase
{
    use RefreshDatabase;

    private const PNG = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    private User $utilisateur;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->seed(PlanSeeder::class);

        $this->utilisateur = User::factory()->create([
            'nom' => 'Makaya',
            'prenom' => 'Jean',
            'password' => 'MotDePasse1!',
        ]);

        Sanctum::actingAs($this->utilisateur);
    }

    private function supprimer(array $remplacements = []): TestResponse
    {
        return $this->deleteJson('/api/v1/compte', array_merge([
            'password' => 'MotDePasse1!',
            'confirmation' => true,
        ], $remplacements));
    }

    public function test_le_mot_de_passe_est_redemande(): void
    {
        // Un téléphone laissé déverrouillé ne doit pas suffire.
        $this->supprimer(['password' => 'mauvais'])->assertStatus(422);

        $this->assertSame('Makaya', $this->utilisateur->fresh()->nom);
    }

    public function test_la_confirmation_explicite_est_exigee(): void
    {
        $this->supprimer(['confirmation' => false])
            ->assertStatus(422)
            ->assertJsonValidationErrors('confirmation');
    }

    public function test_l_identite_est_effacee(): void
    {
        $this->supprimer()->assertOk();

        $apres = $this->utilisateur->fresh();

        $this->assertSame('Compte supprimé', $apres->nom);
        $this->assertNull($apres->prenom);
        $this->assertStringEndsWith('@maboko.invalid', $apres->email);
        $this->assertSame(User::STATUT_SUSPENDU, $apres->statut);
    }

    public function test_les_sessions_sont_revoquees(): void
    {
        $jeton = $this->utilisateur->createToken('mobile')->plainTextToken;

        $this->supprimer()->assertOk();

        app('auth')->forgetGuards();
        $this->withHeader('Authorization', 'Bearer '.$jeton)
            ->getJson('/api/v1/user')
            ->assertStatus(401);
    }

    public function test_les_publications_et_appareils_disparaissent(): void
    {
        Post::create([
            'artisan_id' => $this->utilisateur->id,
            'artisan_category' => 'Menuisier',
            'image_url' => 'https://exemple.cg/photo.jpg',
            'description' => 'Une réalisation.',
        ]);
        Appareil::create([
            'user_id' => $this->utilisateur->id,
            'jeton_push' => 'jeton-abc',
            'plateforme' => 'android',
        ]);

        $this->supprimer()->assertOk();

        $this->assertDatabaseCount('posts', 0);
        $this->assertDatabaseCount('appareils', 0);
    }

    public function test_les_pieces_d_identite_sont_effacees_du_disque(): void
    {
        $chemin = app(MediaService::class)->enregistrerPiecePrivee(self::PNG, 'identites');

        VerificationIdentite::create([
            'user_id' => $this->utilisateur->id,
            'type_piece' => 'cni',
            'chemin_recto' => $chemin,
            'statut' => 'valide',
        ]);

        $this->supprimer()->assertOk();

        Storage::disk('local')->assertMissing($chemin);
        $this->assertDatabaseCount('verifications_identite', 0);
    }

    public function test_le_contenu_des_messages_est_efface_mais_le_fil_garde_son_sens(): void
    {
        $autre = User::factory()->create();
        $conversation = Conversation::create(['type' => Conversation::TYPE_CLIENT_ARTISAN]);
        $conversation->participants()->attach([$this->utilisateur->id, $autre->id]);

        Message::create([
            'conversation_id' => $conversation->id,
            'expediteur_id' => $this->utilisateur->id,
            'contenu' => 'Mon numéro est le 06 12 34 56',
        ]);

        $this->supprimer()->assertOk();

        $message = Message::first();
        $this->assertSame('Message supprimé', $message->contenu);
        // Le message reste dans le fil : sans lui, la conversation de l'autre
        // partie deviendrait incompréhensible.
        $this->assertDatabaseCount('messages', 1);
    }

    public function test_les_transactions_sont_conservees_pour_la_comptabilite(): void
    {
        Transaction::create([
            'user_id' => $this->utilisateur->id,
            'payable_type' => Plan::class,
            'payable_id' => Plan::where('slug', 'pro')->value('id'),
            'montant' => 5000,
            'devise' => 'XAF',
            'operateur' => Transaction::OPERATEUR_MTN,
            'reference_interne' => 'MBK-COMPTA',
            'statut' => Transaction::STATUT_REUSSIE,
            'payee_at' => now(),
        ]);

        $this->supprimer()->assertOk();

        // Le droit à l'effacement ne prime pas sur l'obligation de conserver
        // les pièces comptables : la transaction reste, rattachée à un compte
        // désormais anonyme.
        $this->assertDatabaseHas('transactions', ['reference_interne' => 'MBK-COMPTA']);
    }

    public function test_la_fiche_artisan_cesse_d_apparaitre_dans_la_recherche(): void
    {
        $artisan = Artisan::factory()
            ->for(User::factory()->artisan()->state(['password' => 'MotDePasse1!']), 'utilisateur')
            ->create(['statut_validation' => Artisan::VALIDATION_VALIDE]);

        Sanctum::actingAs($artisan->utilisateur);
        $this->supprimer()->assertOk();

        $this->assertFalse(Artisan::valides()->get()->contains('id', $artisan->id));
    }

    public function test_deux_comptes_supprimes_ne_provoquent_pas_de_collision(): void
    {
        $second = User::factory()->create(['password' => 'MotDePasse1!']);

        $this->supprimer()->assertOk();

        Sanctum::actingAs($second);
        $this->supprimer()->assertOk();

        // Les colonnes e-mail et téléphone sont uniques : deux effacements
        // successifs ne doivent pas entrer en collision.
        $this->assertSame(2, User::where('nom', 'Compte supprimé')->count());
    }

    public function test_la_suppression_exige_une_authentification(): void
    {
        app('auth')->forgetGuards();

        $this->deleteJson('/api/v1/compte', [])->assertStatus(401);
    }
}
