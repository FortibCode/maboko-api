<?php

namespace Tests\Feature\Admin;

use App\Models\Artisan;
use App\Models\AuditLog;
use App\Models\Chauffeur;
use App\Models\User;
use Database\Seeders\BadgeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class GestionComptesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(BadgeSeeder::class);

        $this->admin = User::factory()->admin()->create();
        Sanctum::actingAs($this->admin);
    }

    public function test_admin_peut_lister_tous_les_utilisateurs_par_role_et_recherche(): void
    {
        User::factory()->count(2)->create(['role' => User::ROLE_CLIENT]);
        User::factory()->artisan()->create();

        $reponse = $this->getJson('/api/v1/admin/utilisateurs?role=client')
            ->assertOk();

        $reponse->assertJsonCount(2, 'data');
    }

    public function test_l_annuaire_des_artisans_expose_ce_qu_il_faut_pour_decider(): void
    {
        Artisan::factory()->create(['statut_validation' => Artisan::VALIDATION_EN_ATTENTE]);

        $this->getJson('/api/v1/admin/artisans')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [[
                    'id', 'nomComplet', 'email', 'telephone', 'specialite',
                    'statutValidation', 'compteSuspendu', 'noteMoyenne', 'plan',
                ]],
            ]);
    }

    public function test_l_annuaire_se_filtre_par_statut(): void
    {
        Artisan::factory()->create(['statut_validation' => Artisan::VALIDATION_EN_ATTENTE]);
        Artisan::factory()->create(['statut_validation' => Artisan::VALIDATION_VALIDE]);

        $this->getJson('/api/v1/admin/artisans?statut=en_attente')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_la_validation_d_un_artisan_le_rend_visible_dans_la_recherche(): void
    {
        $artisan = Artisan::factory()->create(['statut_validation' => Artisan::VALIDATION_EN_ATTENTE]);

        $this->postJson("/api/v1/admin/artisans/{$artisan->id}/validation", ['decision' => 'valide'])
            ->assertOk()
            ->assertJsonPath('statutValidation', 'valide');

        $this->assertNotNull($artisan->fresh()->valide_at);
        $this->assertTrue(Artisan::valides()->get()->contains('id', $artisan->id));
    }

    public function test_le_refus_est_notifie_avec_son_motif(): void
    {
        $artisan = Artisan::factory()->create(['statut_validation' => Artisan::VALIDATION_EN_ATTENTE]);

        $this->postJson("/api/v1/admin/artisans/{$artisan->id}/validation", [
            'decision' => 'rejete',
            'motif' => 'Pièces illisibles.',
        ])->assertOk();

        $this->assertDatabaseHas('notifications', [
            'artisan_id' => $artisan->utilisateur_id,
            'type' => 'validation_profil',
        ]);
    }

    public function test_un_chauffeur_se_valide_par_la_meme_route(): void
    {
        $chauffeur = Chauffeur::factory()->create(['statut_validation' => Artisan::VALIDATION_EN_ATTENTE]);

        $this->postJson("/api/v1/admin/chauffeurs/{$chauffeur->id}/validation", ['decision' => 'valide'])
            ->assertOk();

        $this->assertSame('valide', $chauffeur->fresh()->statut_validation);
    }

    // ------------------------------------------------------------------
    // Suspension (§5.4.3)
    // ------------------------------------------------------------------

    public function test_la_suspension_revoque_les_sessions_ouvertes(): void
    {
        $artisan = Artisan::factory()->create();
        $utilisateur = $artisan->utilisateur;
        $jeton = $utilisateur->createToken('mobile')->plainTextToken;

        $this->postJson("/api/v1/admin/utilisateurs/{$utilisateur->id}/suspension", [
            'suspendre' => true,
            'motif' => 'Comportement abusif',
        ])->assertOk();

        $this->assertSame(User::STATUT_SUSPENDU, $utilisateur->fresh()->statut);

        // Sans révocation, le compte suspendu resterait actif jusqu'à
        // l'expiration de son jeton.
        // Le actingAs du setUp doit être levé, sinon il masque l'en-tête.
        app('auth')->forgetGuards();

        $this->withHeader('Authorization', 'Bearer '.$jeton)
            ->getJson('/api/v1/user')
            ->assertStatus(401);
    }

    public function test_un_compte_suspendu_ne_peut_plus_se_connecter(): void
    {
        $utilisateur = User::factory()->create(['password' => 'MotDePasse1!']);

        $this->postJson("/api/v1/admin/utilisateurs/{$utilisateur->id}/suspension", ['suspendre' => true]);

        app('auth')->forgetGuards();
        $this->postJson('/api/v1/login', [
            'identifiant' => $utilisateur->email,
            'password' => 'MotDePasse1!',
        ])->assertForbidden();
    }

    public function test_la_reactivation_remet_le_compte_en_service(): void
    {
        $utilisateur = User::factory()->create(['statut' => User::STATUT_SUSPENDU]);

        $this->postJson("/api/v1/admin/utilisateurs/{$utilisateur->id}/suspension", ['suspendre' => false])
            ->assertOk();

        $this->assertSame(User::STATUT_ACTIF, $utilisateur->fresh()->statut);
    }

    public function test_un_compte_d_administration_ne_se_suspend_pas_ici(): void
    {
        $autreAdmin = User::factory()->admin()->create();

        $this->postJson("/api/v1/admin/utilisateurs/{$autreAdmin->id}/suspension", ['suspendre' => true])
            ->assertStatus(422);

        $this->assertSame(User::STATUT_ACTIF, $autreAdmin->fresh()->statut);
    }

    // ------------------------------------------------------------------
    // Badges et traçabilité
    // ------------------------------------------------------------------

    public function test_l_administration_attribue_puis_retire_un_badge(): void
    {
        $artisan = Artisan::factory()->create();

        $this->postJson("/api/v1/admin/artisans/{$artisan->id}/badge", ['badge' => 'certifie-maboko'])
            ->assertCreated();

        $this->assertSame(1, $artisan->badges()->count());

        $this->postJson("/api/v1/admin/artisans/{$artisan->id}/badge", [
            'badge' => 'certifie-maboko',
            'retirer' => true,
        ])->assertOk();

        $this->assertSame(0, $artisan->badges()->count());
    }

    public function test_chaque_decision_laisse_une_trace_nominative(): void
    {
        $artisan = Artisan::factory()->create(['statut_validation' => Artisan::VALIDATION_EN_ATTENTE]);

        $this->postJson("/api/v1/admin/artisans/{$artisan->id}/validation", ['decision' => 'valide']);
        $this->postJson("/api/v1/admin/utilisateurs/{$artisan->utilisateur_id}/suspension", ['suspendre' => true]);

        // Suspendre un compte est une décision opposable : elle doit laisser
        // une trace nominative, horodatée, avec l'état avant et après.
        $traces = AuditLog::where('user_id', $this->admin->id)->get();

        $this->assertCount(2, $traces);
        $this->assertSame(['compte.artisans.valide', 'compte.suspendu'], $traces->pluck('action')->all());
        $this->assertSame(User::STATUT_ACTIF, $traces->last()->avant['statut']);
        $this->assertSame(User::STATUT_SUSPENDU, $traces->last()->apres['statut']);
        $this->assertNotNull($traces->last()->adresse_ip);
    }
}
