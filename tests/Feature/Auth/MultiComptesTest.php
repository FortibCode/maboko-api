<?php

namespace Tests\Feature\Auth;

use App\Models\Artisan;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Multi-comptes artisan, sur le modèle d'Instagram : un compte principal,
 * jusqu'à trois comptes au total, et un passage de l'un à l'autre sans
 * mot de passe ni code SMS.
 */
class MultiComptesTest extends TestCase
{
    use RefreshDatabase;

    private function artisanPrincipal(): User
    {
        $principal = User::factory()->create(['role' => User::ROLE_ARTISAN, 'password' => 'secret123']);
        Artisan::creerFicheProvisoire($principal);

        return $principal;
    }

    /** Ajoute un profil avec une vraie session, et rend le profil et son jeton. */
    private function ajouterProfil(User $depuis, string $nom): array
    {
        $jeton = $depuis->createToken('test')->plainTextToken;

        $reponse = $this->withToken($jeton)
            ->postJson('/api/v1/comptes', ['nom' => $nom])
            ->assertCreated();

        $this->app['auth']->forgetGuards();

        return [User::findOrFail($reponse->json('user.id')), $reponse->json('token')];
    }

    public function test_un_artisan_ajoute_un_profil_avec_son_seul_nom(): void
    {
        $principal = $this->artisanPrincipal();

        [$profil, $jeton] = $this->ajouterProfil($principal, 'Couture Mama');

        $this->assertSame($principal->id, $profil->compte_principal_id);
        $this->assertSame($principal->telephone, $profil->telephone);
        $this->assertSame($principal->email, $profil->email);
        $this->assertSame(User::ROLE_ARTISAN, $profil->role);
        $this->assertNotNull($profil->artisan, 'Le profil doit avoir sa propre fiche artisan.');
        $this->assertNotSame($principal->artisan->id, $profil->artisan->id);

        // La session renvoyée est celle du nouveau profil.
        $this->withToken($jeton)->getJson('/api/v1/user')->assertJsonPath('id', $profil->id);
    }

    public function test_trois_comptes_au_maximum(): void
    {
        $principal = $this->artisanPrincipal();
        $this->ajouterProfil($principal, 'Profil 2');
        $this->ajouterProfil($principal, 'Profil 3');

        Sanctum::actingAs($principal);
        $this->postJson('/api/v1/comptes', ['nom' => 'Profil 4'])->assertUnprocessable();

        $this->assertSame(3, $principal->famille()->count());
    }

    public function test_un_profil_rattache_peut_aussi_ajouter_un_compte(): void
    {
        $principal = $this->artisanPrincipal();
        [$profil] = $this->ajouterProfil($principal, 'Profil 2');

        [$troisieme] = $this->ajouterProfil($profil, 'Profil 3');

        // Rattaché au compte principal, jamais en chaîne.
        $this->assertSame($principal->id, $troisieme->compte_principal_id);
    }

    public function test_un_client_ne_cree_pas_de_profils(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => User::ROLE_CLIENT]));

        $this->postJson('/api/v1/comptes', ['nom' => 'Autre'])->assertForbidden();
    }

    public function test_la_connexion_ouvre_toujours_le_compte_principal(): void
    {
        $principal = $this->artisanPrincipal();
        $this->ajouterProfil($principal, 'Profil 2');
        $this->ajouterProfil($principal, 'Profil 3');

        foreach ([$principal->telephone, $principal->email] as $identifiant) {
            $this->postJson('/api/v1/login', ['identifiant' => $identifiant, 'password' => 'secret123'])
                ->assertOk()
                ->assertJsonPath('user.id', $principal->id);
        }
    }

    public function test_on_bascule_d_un_compte_a_l_autre_sans_mot_de_passe(): void
    {
        $principal = $this->artisanPrincipal();
        [$profil, $jetonProfil] = $this->ajouterProfil($principal, 'Profil 2');

        // Du profil vers le compte principal…
        $retour = $this->withToken($jetonProfil)
            ->postJson("/api/v1/comptes/{$principal->id}/basculer")
            ->assertOk()
            ->assertJsonPath('user.id', $principal->id);

        // …l'ancienne session est fermée…
        $this->assertNull(PersonalAccessToken::findToken($jetonProfil));
        $this->app['auth']->forgetGuards();

        // …et l'on peut revenir sur le profil.
        $this->withToken($retour->json('token'))
            ->postJson("/api/v1/comptes/{$profil->id}/basculer")
            ->assertOk()
            ->assertJsonPath('user.id', $profil->id);
    }

    public function test_on_ne_bascule_pas_vers_le_compte_d_une_autre_personne(): void
    {
        $moi = $this->artisanPrincipal();
        $autre = $this->artisanPrincipal();

        Sanctum::actingAs($moi);

        $this->postJson("/api/v1/comptes/{$autre->id}/basculer")->assertNotFound();
    }

    public function test_la_liste_montre_les_comptes_et_leurs_notifications(): void
    {
        $principal = $this->artisanPrincipal();
        [$profil] = $this->ajouterProfil($principal, 'Profil 2');
        Notification::create(['artisan_id' => $profil->id, 'title' => 'Avis', 'body' => 'Nouvel avis']);

        Sanctum::actingAs($principal);

        $this->getJson('/api/v1/comptes')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $principal->id)
            ->assertJsonPath('data.0.estPrincipal', true)
            ->assertJsonPath('data.0.estActif', true)
            ->assertJsonPath('data.1.id', $profil->id)
            ->assertJsonPath('data.1.notificationsNonLues', 1)
            ->assertJsonPath('peutAjouter', true);
    }

    public function test_les_profils_sont_des_comptes_distincts(): void
    {
        $principal = $this->artisanPrincipal();
        [$profil] = $this->ajouterProfil($principal, 'Couture Mama');

        Sanctum::actingAs($profil);
        $this->patchJson('/api/v1/compte', ['nom' => 'Couture Mama Pro'])->assertOk();

        $this->assertSame('Couture Mama Pro', $profil->fresh()->nom);
        $this->assertNotSame('Couture Mama Pro', $principal->fresh()->nom);
    }

    public function test_l_inscription_refuse_toujours_un_numero_deja_pris(): void
    {
        $principal = $this->artisanPrincipal();
        $this->ajouterProfil($principal, 'Profil 2');

        $this->postJson('/api/v1/send-register-otp', [
            'nom' => 'Intrus',
            'email' => 'intrus@example.cg',
            'telephone' => $principal->telephone,
            'password' => 'motdepasse',
        ])->assertUnprocessable()->assertJsonValidationErrors('telephone');
    }

    public function test_l_email_suit_le_compte_principal(): void
    {
        $principal = $this->artisanPrincipal();
        [$profil] = $this->ajouterProfil($principal, 'Profil 2');

        Sanctum::actingAs($profil);
        $this->patchJson('/api/v1/compte', ['email' => 'nouveau@example.cg'])->assertUnprocessable();

        Sanctum::actingAs($principal);
        $this->patchJson('/api/v1/compte', ['email' => 'nouveau@example.cg'])->assertOk();

        $this->assertSame('nouveau@example.cg', $profil->fresh()->email);
    }

    public function test_supprimer_un_profil_garde_le_compte_principal(): void
    {
        $principal = $this->artisanPrincipal();
        [$profil] = $this->ajouterProfil($principal, 'Profil 2');

        Sanctum::actingAs($profil);
        // Le mot de passe demandé est celui du compte principal.
        $this->deleteJson('/api/v1/compte', ['password' => 'secret123', 'confirmation' => true])->assertOk();

        $this->assertSame(1, $principal->famille()->count());
        $this->assertSame(User::STATUT_ACTIF, $principal->fresh()->statut);
    }

    public function test_supprimer_le_compte_principal_emporte_ses_profils(): void
    {
        $principal = $this->artisanPrincipal();
        [$profil] = $this->ajouterProfil($principal, 'Profil 2');

        Sanctum::actingAs($principal);
        $this->deleteJson('/api/v1/compte', ['password' => 'secret123', 'confirmation' => true])->assertOk();

        $this->assertSame(User::STATUT_SUSPENDU, $principal->fresh()->statut);
        $this->assertSame(User::STATUT_SUSPENDU, $profil->fresh()->statut);
    }

    public function test_l_admin_qui_supprime_le_compte_principal_supprime_ses_profils(): void
    {
        $principal = $this->artisanPrincipal();
        [$profil] = $this->ajouterProfil($principal, 'Profil 2');

        Sanctum::actingAs(User::factory()->admin()->create());
        $this->deleteJson("/api/v1/admin/utilisateurs/{$principal->id}")->assertOk();

        $this->assertDatabaseMissing('users', ['id' => $principal->id]);
        $this->assertDatabaseMissing('users', ['id' => $profil->id]);
    }

    public function test_un_compte_principal_suspendu_ferme_tous_ses_profils(): void
    {
        $principal = $this->artisanPrincipal();
        [, $jetonProfil] = $this->ajouterProfil($principal, 'Profil 2');

        Sanctum::actingAs(User::factory()->admin()->create());
        $this->postJson("/api/v1/admin/utilisateurs/{$principal->id}/suspension", ['suspendre' => true])->assertOk();
        $this->app['auth']->forgetGuards();

        $this->assertNull(PersonalAccessToken::findToken($jetonProfil));
        $this->postJson('/api/v1/login', ['identifiant' => $principal->telephone, 'password' => 'secret123'])
            ->assertForbidden();
    }
}
