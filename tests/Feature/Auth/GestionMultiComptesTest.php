<?php

namespace Tests\Feature\Auth;

use App\Models\Artisan;
use App\Models\Notification;
use App\Models\OtpCode;
use App\Models\User;
use Database\Seeders\MetierSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Gestion des comptes d'une même personne : création complète, compte
 * principal, archivage, association d'un compte existant, suppression,
 * limite configurable et isolation entre personnes.
 */
class GestionMultiComptesTest extends TestCase
{
    use RefreshDatabase;

    private const PNG = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAAC0lEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    private function nouvelArtisan(array $attributs = []): User
    {
        $compte = User::factory()->create($attributs + ['role' => User::ROLE_ARTISAN, 'password' => 'secret123']);
        Artisan::creerFicheProvisoire($compte);

        return $compte;
    }

    private function profilDe(User $principal, string $nom = 'Profil'): User
    {
        $profil = User::factory()->create([
            'role' => User::ROLE_ARTISAN,
            'compte_principal_id' => $principal->id,
            'telephone' => $principal->telephone,
            'email' => $principal->email,
            'nom' => $nom,
        ]);
        Artisan::creerFicheProvisoire($profil);

        return $profil;
    }

    /** Le code parti par SMS au numéro du compte visé. */
    private function codeEnvoyeA(User $compte): string
    {
        return OtpCode::where('telephone', $compte->telephone)->latest('id')->value('code');
    }

    /** Jeton réel : une session Sanctum::actingAs n'a pas de jeton à révoquer. */
    private function jeton(User $compte): string
    {
        return $compte->createToken('test')->plainTextToken;
    }

    // ------------------------------------------------------------------
    // Création complète (§5A)
    // ------------------------------------------------------------------

    public function test_le_nouveau_compte_recoit_specialite_metiers_bio_et_photo(): void
    {
        Storage::fake('public');
        $this->seed(MetierSeeder::class);
        $principal = $this->nouvelArtisan();

        $reponse = $this->withToken($this->jeton($principal))->postJson('/api/v1/comptes', [
            'nom' => 'Atelier Bois Mbemba',
            'metiers' => ['menuisier'],
            'bio' => 'Mobilier sur mesure.',
            'photo' => self::PNG,
        ])->assertCreated();

        $profil = User::findOrFail($reponse->json('user.id'));

        $this->assertNotNull($profil->avatar_url);
        $this->assertSame('Menuisier', $profil->artisan->specialite);
        $this->assertSame('Mobilier sur mesure.', $profil->artisan->bio);
        $this->assertSame(['menuisier'], $profil->artisan->metiers->pluck('slug')->all());
    }

    public function test_une_photo_illisible_ne_cree_aucun_compte(): void
    {
        $principal = $this->nouvelArtisan();
        Sanctum::actingAs($principal);

        $this->postJson('/api/v1/comptes', ['nom' => 'X', 'photo' => 'pas-une-image'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('photo');

        $this->assertSame(1, $principal->famille()->count());
    }

    public function test_la_liste_donne_la_specialite_et_separe_les_archives(): void
    {
        $principal = $this->nouvelArtisan();
        $profil = $this->profilDe($principal, 'Couture');
        $profil->artisan->update(['specialite' => 'Couturière']);
        $archive = $this->profilDe($principal, 'Ancien');
        $archive->update(['archive_at' => now()]);

        Sanctum::actingAs($principal);

        $this->getJson('/api/v1/comptes')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.1.specialite', 'Couturière')
            ->assertJsonCount(1, 'archives')
            ->assertJsonPath('archives.0.id', $archive->id);
    }

    // ------------------------------------------------------------------
    // Limite configurable (§10)
    // ------------------------------------------------------------------

    public function test_la_limite_se_regle_cote_serveur(): void
    {
        config(['multi_comptes.maximum' => 2]);
        $principal = $this->nouvelArtisan();
        $this->profilDe($principal);
        Sanctum::actingAs($principal);

        $this->getJson('/api/v1/comptes')->assertJsonPath('maximum', 2)->assertJsonPath('peutAjouter', false);
        $this->postJson('/api/v1/comptes', ['nom' => 'Trop'])->assertUnprocessable();
    }

    // ------------------------------------------------------------------
    // Compte principal (§6)
    // ------------------------------------------------------------------

    public function test_un_autre_compte_devient_principal_et_porte_la_connexion(): void
    {
        $principal = $this->nouvelArtisan();
        $profil = $this->profilDe($principal);
        $troisieme = $this->profilDe($principal);
        Sanctum::actingAs($principal);

        $this->postJson("/api/v1/comptes/{$profil->id}/principal", ['password' => 'secret123'])->assertOk();

        $this->assertNull($profil->fresh()->compte_principal_id);
        $this->assertSame($profil->id, $principal->fresh()->compte_principal_id);
        $this->assertSame($profil->id, $troisieme->fresh()->compte_principal_id);

        // Même numéro, même mot de passe : la connexion ouvre le nouveau principal.
        $this->postJson('/api/v1/login', ['identifiant' => $principal->telephone, 'password' => 'secret123'])
            ->assertOk()
            ->assertJsonPath('user.id', $profil->id);
    }

    public function test_changer_de_principal_exige_le_mot_de_passe(): void
    {
        $principal = $this->nouvelArtisan();
        $profil = $this->profilDe($principal);
        Sanctum::actingAs($principal);

        $this->postJson("/api/v1/comptes/{$profil->id}/principal", ['password' => 'faux'])->assertUnprocessable();

        $this->assertSame($principal->id, $profil->fresh()->compte_principal_id);
    }

    // ------------------------------------------------------------------
    // Archivage (retrait sans perte de données)
    // ------------------------------------------------------------------

    public function test_archiver_retire_le_compte_sans_rien_supprimer(): void
    {
        $principal = $this->nouvelArtisan();
        $profil = $this->profilDe($principal);
        $profil->artisan->update(['statut_validation' => Artisan::VALIDATION_VALIDE]);
        Notification::create(['artisan_id' => $profil->id, 'title' => 'Commande', 'body' => 'Nouvelle demande']);
        $jetonProfil = $this->jeton($profil);
        Sanctum::actingAs($principal);

        $this->postJson("/api/v1/comptes/{$profil->id}/archiver", ['confirmation' => true])->assertOk();

        $this->assertNotNull($profil->fresh()->archive_at);
        $this->assertNull(PersonalAccessToken::findToken($jetonProfil));
        $this->assertDatabaseHas('artisans', ['utilisateur_id' => $profil->id]);
        $this->assertDatabaseHas('notifications', ['artisan_id' => $profil->id]);

        // Il ne reçoit plus de nouvelles demandes, et ne s'ouvre plus.
        $this->getJson('/api/v1/artisans')->assertJsonMissing(['id' => $profil->artisan->id]);
        $this->postJson("/api/v1/comptes/{$profil->id}/basculer")->assertUnprocessable();
    }

    public function test_archiver_le_compte_ouvert_ramene_sur_le_compte_principal(): void
    {
        $principal = $this->nouvelArtisan();
        $profil = $this->profilDe($principal);

        $this->withToken($this->jeton($profil))
            ->postJson("/api/v1/comptes/{$profil->id}/archiver", ['confirmation' => true])
            ->assertOk()
            ->assertJsonPath('user.id', $principal->id)
            ->assertJsonStructure(['token']);
    }

    public function test_le_compte_principal_ne_s_archive_pas(): void
    {
        $principal = $this->nouvelArtisan();
        $this->profilDe($principal);
        Sanctum::actingAs($principal);

        $this->postJson("/api/v1/comptes/{$principal->id}/archiver", ['confirmation' => true])->assertUnprocessable();
    }

    public function test_reactiver_un_compte_respecte_la_limite(): void
    {
        $principal = $this->nouvelArtisan();
        $archive = $this->profilDe($principal);
        $archive->update(['archive_at' => now()]);
        $this->profilDe($principal);
        $this->profilDe($principal);
        Sanctum::actingAs($principal);

        // 3 comptes actifs : pas de place pour un 4e.
        $this->postJson("/api/v1/comptes/{$archive->id}/reactiver")->assertUnprocessable();

        config(['multi_comptes.maximum' => 4]);
        $this->postJson("/api/v1/comptes/{$archive->id}/reactiver")->assertOk();
        $this->assertNull($archive->fresh()->archive_at);
    }

    // ------------------------------------------------------------------
    // Association d'un compte existant (§5B)
    // ------------------------------------------------------------------

    public function test_associer_un_compte_existant_avec_mot_de_passe_et_code_sms(): void
    {
        $principal = $this->nouvelArtisan();
        $existant = $this->nouvelArtisan(['password' => 'autre-secret']);
        Sanctum::actingAs($principal);

        $this->postJson('/api/v1/comptes/associations', [
            'identifiant' => $existant->telephone,
            'password' => 'autre-secret',
        ])->assertOk()->assertJsonPath('compte.id', $existant->id);

        $this->postJson('/api/v1/comptes/associations/confirmer', [
            'compte_id' => $existant->id,
            'code' => $this->codeEnvoyeA($existant),
            'confirmation' => true,
        ])->assertOk();

        $existant->refresh();
        $this->assertSame($principal->id, $existant->compte_principal_id);
        $this->assertSame($principal->telephone, $existant->telephone);

        // Il ne se connecte plus seul : c'est le compte principal qui s'ouvre.
        $this->postJson('/api/v1/login', ['identifiant' => $principal->telephone, 'password' => 'secret123'])
            ->assertJsonPath('user.id', $principal->id);
    }

    public function test_l_association_exige_le_bon_mot_de_passe(): void
    {
        $principal = $this->nouvelArtisan();
        $existant = $this->nouvelArtisan(['password' => 'autre-secret']);
        Sanctum::actingAs($principal);

        $this->postJson('/api/v1/comptes/associations', ['identifiant' => $existant->telephone, 'password' => 'devine'])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Identifiant ou mot de passe incorrect.');
    }

    public function test_l_association_exige_le_bon_code_et_une_confirmation(): void
    {
        $principal = $this->nouvelArtisan();
        $existant = $this->nouvelArtisan(['password' => 'autre-secret']);
        Sanctum::actingAs($principal);

        $this->postJson('/api/v1/comptes/associations', ['identifiant' => $existant->email, 'password' => 'autre-secret'])->assertOk();

        $this->postJson('/api/v1/comptes/associations/confirmer', [
            'compte_id' => $existant->id, 'code' => '000000', 'confirmation' => true,
        ])->assertUnprocessable();
        $this->postJson('/api/v1/comptes/associations/confirmer', [
            'compte_id' => $existant->id, 'code' => '000000',
        ])->assertJsonValidationErrors('confirmation');

        $this->assertNull($existant->fresh()->compte_principal_id);
    }

    public function test_une_autre_personne_ne_peut_pas_confirmer_une_demande_qui_n_est_pas_la_sienne(): void
    {
        $principal = $this->nouvelArtisan();
        $intrus = $this->nouvelArtisan();
        $existant = $this->nouvelArtisan(['password' => 'autre-secret']);

        Sanctum::actingAs($principal);
        $this->postJson('/api/v1/comptes/associations', [
            'identifiant' => $existant->telephone, 'password' => 'autre-secret',
        ])->assertOk();
        $code = $this->codeEnvoyeA($existant);

        Sanctum::actingAs($intrus);
        $this->postJson('/api/v1/comptes/associations/confirmer', [
            'compte_id' => $existant->id, 'code' => $code, 'confirmation' => true,
        ])->assertUnprocessable();

        $this->assertNull($existant->fresh()->compte_principal_id);
    }

    public function test_les_comptes_ne_fusionnent_jamais(): void
    {
        $principal = $this->nouvelArtisan();
        $autreFamille = $this->nouvelArtisan(['password' => 'autre-secret']);
        $this->profilDe($autreFamille);
        Sanctum::actingAs($principal);

        $this->postJson('/api/v1/comptes/associations', ['identifiant' => $autreFamille->telephone, 'password' => 'autre-secret'])
            ->assertUnprocessable();
    }

    // ------------------------------------------------------------------
    // Suppression définitive d'un compte rattaché
    // ------------------------------------------------------------------

    public function test_supprimer_un_compte_rattache_exige_mot_de_passe_et_confirmation(): void
    {
        $principal = $this->nouvelArtisan();
        $profil = $this->profilDe($principal);
        Sanctum::actingAs($principal);

        $this->deleteJson("/api/v1/comptes/{$profil->id}", ['password' => 'faux', 'confirmation' => true])->assertUnprocessable();
        $this->deleteJson("/api/v1/comptes/{$profil->id}", ['password' => 'secret123'])->assertJsonValidationErrors('confirmation');

        $this->deleteJson("/api/v1/comptes/{$profil->id}", ['password' => 'secret123', 'confirmation' => true])->assertOk();

        $this->assertSame(User::STATUT_SUSPENDU, $profil->fresh()->statut);
        $this->assertSame(1, $principal->famille()->count());
    }

    // ------------------------------------------------------------------
    // Isolation (§7, §9)
    // ------------------------------------------------------------------

    public function test_aucune_operation_n_atteint_le_compte_d_une_autre_personne(): void
    {
        $moi = $this->nouvelArtisan();
        $autre = $this->nouvelArtisan();
        $sonProfil = $this->profilDe($autre);
        Sanctum::actingAs($moi);

        foreach ([$autre, $sonProfil] as $cible) {
            $this->postJson("/api/v1/comptes/{$cible->id}/basculer")->assertNotFound();
            $this->postJson("/api/v1/comptes/{$cible->id}/principal", ['password' => 'secret123'])->assertNotFound();
            $this->postJson("/api/v1/comptes/{$cible->id}/archiver", ['confirmation' => true])->assertNotFound();
            $this->postJson("/api/v1/comptes/{$cible->id}/reactiver")->assertNotFound();
            $this->deleteJson("/api/v1/comptes/{$cible->id}", ['password' => 'secret123', 'confirmation' => true])->assertNotFound();
        }

        $this->assertNull($sonProfil->fresh()->archive_at);
        $this->assertSame(User::STATUT_ACTIF, $sonProfil->fresh()->statut);
    }

    public function test_chaque_compte_ne_voit_que_ses_notifications(): void
    {
        $principal = $this->nouvelArtisan();
        $profil = $this->profilDe($principal);
        Notification::create(['artisan_id' => $principal->id, 'title' => 'Pour A', 'body' => 'A']);
        Notification::create(['artisan_id' => $profil->id, 'title' => 'Pour B', 'body' => 'B']);

        Sanctum::actingAs($profil);

        $this->getJson('/api/v1/notifications')
            ->assertOk()
            ->assertJsonFragment(['titre' => 'Pour B'])
            ->assertJsonMissing(['titre' => 'Pour A']);
    }

    public function test_les_changements_rapides_revoquent_chaque_session_quittee(): void
    {
        $principal = $this->nouvelArtisan();
        $b = $this->profilDe($principal);
        $c = $this->profilDe($principal);

        $jeton = $this->jeton($principal);
        $quittes = [];

        foreach ([$b, $c, $principal, $b] as $cible) {
            $quittes[] = $jeton;
            $jeton = $this->withToken($jeton)
                ->postJson("/api/v1/comptes/{$cible->id}/basculer")
                ->assertOk()
                ->assertJsonPath('user.id', $cible->id)
                ->json('token');
            $this->app['auth']->forgetGuards();
        }

        foreach ($quittes as $ancien) {
            $this->assertNull(PersonalAccessToken::findToken($ancien));
        }
        $this->withToken($jeton)->getJson('/api/v1/user')->assertJsonPath('id', $b->id);
    }
}
