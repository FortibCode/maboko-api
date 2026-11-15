<?php

namespace App\Services;

use App\Exceptions\OperationCompteRefusee;
use App\Models\Artisan;
use App\Models\Metier;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Multi-comptes artisan, sur le modèle d'Instagram.
 *
 * Une personne se connecte avec son compte principal (téléphone, email, mot
 * de passe) et réunit jusqu'à User::maximumComptes() comptes actifs. Chaque
 * compte rattaché est un compte complet — fiche, publications, commandes,
 * avis, messages, notifications — qui partage le téléphone et l'email du
 * compte principal. Les vérifications d'appartenance vivent dans
 * ComptePolicy ; ce service porte les règles métier.
 */
class MultiComptes
{
    /** Durée de validité d'une demande d'association, code SMS compris. */
    private const ASSOCIATION_MINUTES = 10;

    public function __construct(private OtpService $otp, private MediaService $media) {}

    /**
     * Crée un compte artisan rattaché au compte principal de la personne.
     *
     * @param  array{nom: string, prenom?: ?string, specialite?: ?string, bio?: ?string, metiers?: list<string>, photo?: ?string}  $donnees
     */
    public function creerProfil(User $acteur, array $donnees): User
    {
        $principal = $this->principalArtisan($acteur);
        $this->exigerUnePlaceLibre($principal);

        // Hors transaction : un fichier mal formé refuse la demande avant
        // qu'aucun compte ne soit créé.
        $avatar = empty($donnees['photo'])
            ? null
            : $this->media->enregistrerImage($donnees['photo'], 'avatars');

        return DB::transaction(function () use ($principal, $donnees, $avatar) {
            $this->verrouiller($principal);
            $this->exigerUnePlaceLibre($principal);

            $profil = User::create([
                'compte_principal_id' => $principal->id,
                'nom' => $donnees['nom'],
                'prenom' => $donnees['prenom'] ?? null,
                'telephone' => $principal->telephone,
                'email' => $principal->email,
                'ville' => $principal->ville,
                'quartier' => $principal->quartier,
                'avatar_url' => $avatar,
                // Jamais utilisé : on ne se connecte pas à un compte rattaché.
                'password' => Hash::make(Str::random(64)),
                'role' => User::ROLE_ARTISAN,
                'statut' => User::STATUT_ACTIF,
                'is_verified' => true,
            ]);

            $fiche = Artisan::creerFicheProvisoire($profil);
            $metiers = Metier::whereIn('slug', $donnees['metiers'] ?? [])->get();

            $fiche->update(array_filter([
                'specialite' => $donnees['specialite'] ?? $metiers->first()?->nom,
                'bio' => $donnees['bio'] ?? null,
            ]));
            $fiche->metiers()->sync($metiers->pluck('id'));

            return $profil;
        });
    }

    /**
     * Ouvre une session sur un compte de la famille. Le jeton de la session
     * quittée est révoqué : le téléphone n'en garde qu'une à la fois.
     *
     * @return array{token: string, user: User}
     */
    public function ouvrirSession(User $depuis, User $cible): array
    {
        if ($cible->estArchive()) {
            throw new OperationCompteRefusee('Ce compte est archivé. Réactivez-le d’abord.');
        }

        if ($cible->statut === User::STATUT_SUSPENDU || $cible->titulaire()->statut === User::STATUT_SUSPENDU) {
            throw new OperationCompteRefusee('Ce compte est suspendu. Contactez le support Maboko.', 403);
        }

        $this->revoquerSessionCourante($depuis);

        $cible->forceFill(['derniere_connexion_at' => now()])->save();

        return [
            'token' => $cible->createToken('auth_token')->plainTextToken,
            'user' => $cible->fresh(),
        ];
    }

    /**
     * Fait d'un compte rattaché le compte principal : il porte désormais la
     * connexion, avec le même téléphone, le même email et le même mot de passe.
     */
    public function definirPrincipal(User $nouveau): void
    {
        if (! $nouveau->estProfilRattache()) {
            throw new OperationCompteRefusee('Ce compte est déjà le compte principal.');
        }

        if ($nouveau->estArchive()) {
            throw new OperationCompteRefusee('Réactivez ce compte avant d’en faire le compte principal.');
        }

        $ancien = $nouveau->titulaire();

        DB::transaction(function () use ($ancien, $nouveau) {
            $this->verrouiller($ancien);

            // L'ordre compte : le nouveau principal ne quitte sa place de
            // profil qu'une fois l'ancien rattaché, pour que deux comptes
            // principaux ne partagent jamais le même téléphone.
            User::whereKey($ancien->id)->update(['compte_principal_id' => $nouveau->id]);
            User::where('compte_principal_id', $ancien->id)
                ->whereKeyNot($nouveau->id)
                ->update(['compte_principal_id' => $nouveau->id]);

            // Requête directe : le mot de passe est déjà haché, le modèle le
            // hacherait une seconde fois.
            DB::table('users')->where('id', $nouveau->id)->update([
                'compte_principal_id' => null,
                'password' => $ancien->password,
                'is_verified' => true,
            ]);
            DB::table('users')->where('id', $ancien->id)->update([
                'password' => Hash::make(Str::random(64)),
            ]);
        });
    }

    /** Retire un compte du sélecteur sans rien supprimer de ses données. */
    public function archiver(User $profil): void
    {
        if (! $profil->estProfilRattache()) {
            throw new OperationCompteRefusee(
                'Le compte principal ne s’archive pas. Désignez d’abord un autre compte principal.',
            );
        }

        if ($profil->estArchive()) {
            throw new OperationCompteRefusee('Ce compte est déjà archivé.');
        }

        $profil->update(['archive_at' => now()]);
        $profil->tokens()->delete();
    }

    public function reactiver(User $profil): void
    {
        if (! $profil->estArchive()) {
            throw new OperationCompteRefusee('Ce compte n’est pas archivé.');
        }

        $principal = $profil->titulaire();

        DB::transaction(function () use ($principal, $profil) {
            $this->verrouiller($principal);
            $this->exigerUnePlaceLibre($principal);

            $profil->update(['archive_at' => null]);
        });
    }

    /**
     * Étape 1 de l'association d'un compte existant : identifiants vérifiés,
     * puis un code part au numéro de ce compte, preuve qu'il appartient bien
     * à la personne qui le rattache.
     *
     * @return array{compte: User, code: string}
     */
    public function demanderAssociation(User $acteur, string $identifiant, string $motDePasse): array
    {
        $principal = $this->principalArtisan($acteur);
        $this->exigerUnePlaceLibre($principal);

        $cible = User::principaux()
            ->where(fn ($r) => $r->where('email', $identifiant)->orWhere('telephone', $identifiant))
            ->first();

        // Même réponse pour un compte inconnu et un mauvais mot de passe : la
        // route ne doit pas servir à tester quels numéros sont inscrits.
        if (! $cible || ! Hash::check($motDePasse, $cible->password)) {
            throw new OperationCompteRefusee('Identifiant ou mot de passe incorrect.');
        }

        $this->exigerRattachable($principal, $cible);

        Cache::put($this->cleAssociation($cible), $principal->id, now()->addMinutes(self::ASSOCIATION_MINUTES));

        return [
            'compte' => $cible,
            'code' => $this->otp->emettrePourAssociation($cible->id, $cible->telephone),
        ];
    }

    /**
     * Étape 2 : le code reçu par SMS et la confirmation explicite rattachent
     * le compte. Il se connecte désormais par le compte principal.
     */
    public function confirmerAssociation(User $acteur, int $compteId, string $code): User
    {
        $principal = $this->principalArtisan($acteur);
        $cible = User::principaux()->find($compteId);

        if (! $cible || Cache::get($this->cleAssociation($cible)) !== $principal->id) {
            throw new OperationCompteRefusee('Cette demande d’association a expiré. Recommencez.');
        }

        $otp = $this->otp->verifier($cible->telephone, $code);

        if (! $otp || $otp->user_id !== $cible->id) {
            throw new OperationCompteRefusee('Code invalide ou expiré.');
        }

        DB::transaction(function () use ($principal, $cible, $otp) {
            $this->verrouiller($principal);
            $this->exigerUnePlaceLibre($principal);
            $this->exigerRattachable($principal, $cible->fresh());

            $cible->update([
                'compte_principal_id' => $principal->id,
                'telephone' => $principal->telephone,
                'email' => $principal->email,
                'password' => Hash::make(Str::random(64)),
            ]);
            $cible->tokens()->delete();
            $otp->update(['statut' => true]);
        });

        Cache::forget($this->cleAssociation($cible));

        return $cible->fresh();
    }

    // ------------------------------------------------------------------

    private function principalArtisan(User $acteur): User
    {
        $principal = $acteur->titulaire();

        if (! $principal->estArtisan()) {
            throw new OperationCompteRefusee('Seul un compte artisan peut réunir plusieurs comptes.', 403);
        }

        return $principal;
    }

    private function exigerUnePlaceLibre(User $principal): void
    {
        if ($principal->familleActive()->count() >= User::maximumComptes()) {
            throw new OperationCompteRefusee(
                'Vous avez atteint le maximum de '.User::maximumComptes().' comptes actifs.',
            );
        }
    }

    private function exigerRattachable(User $principal, User $cible): void
    {
        if ($cible->id === $principal->id) {
            throw new OperationCompteRefusee('C’est déjà votre compte.');
        }

        if (! $cible->estArtisan()) {
            throw new OperationCompteRefusee('Seul un compte artisan peut être rattaché.');
        }

        if ($cible->statut === User::STATUT_SUSPENDU) {
            throw new OperationCompteRefusee('Ce compte est suspendu. Contactez le support Maboko.');
        }

        // Les comptes ne fusionnent jamais : un compte qui réunit déjà les
        // siens ne peut pas être absorbé par une autre famille.
        if ($cible->profilsRattaches()->exists()) {
            throw new OperationCompteRefusee('Ce compte a déjà ses propres comptes rattachés.');
        }
    }

    /** Verrou sur le compte principal : deux opérations simultanées s'attendent. */
    private function verrouiller(User $principal): void
    {
        User::whereKey($principal->id)->lockForUpdate()->first();
    }

    private function revoquerSessionCourante(User $depuis): void
    {
        $jeton = $depuis->currentAccessToken();

        // Une session de navigateur (TransientToken) n'a pas de jeton enregistré
        // à révoquer, quoi qu'en dise le type déclaré par Sanctum.
        // @phpstan-ignore instanceof.alwaysTrue
        if ($jeton instanceof PersonalAccessToken) {
            $jeton->delete();
        }
    }

    private function cleAssociation(User $cible): string
    {
        return 'multi-comptes:association:'.$cible->id;
    }
}
