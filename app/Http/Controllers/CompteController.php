<?php

namespace App\Http\Controllers;

use App\Models\Artisan;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Multi-comptes artisan, sur le modèle d'Instagram.
 *
 * Une personne se connecte avec son compte principal et peut y rattacher des
 * profils, jusqu'à User::MAX_COMPTES comptes au total. Chaque profil est un
 * compte complet (nom, fiche, publications, messages) qui partage le
 * téléphone et l'email du compte principal. Passer d'un compte à l'autre ne
 * redemande ni mot de passe ni code SMS : la session en cours suffit, car
 * elle appartient à la même personne.
 */
class CompteController extends Controller
{
    /** Comptes de la personne connectée, pour le sélecteur. */
    public function index(Request $request): JsonResponse
    {
        $utilisateur = $request->user();

        // Compte principal en tête, puis les profils dans l'ordre de création.
        $comptes = $utilisateur->famille()
            ->orderByRaw('compte_principal_id IS NOT NULL')
            ->orderBy('id')
            ->get();

        $nonLues = Notification::query()
            ->whereIn('artisan_id', $comptes->pluck('id'))
            ->whereNull('lu_at')
            ->selectRaw('artisan_id, count(*) as total')
            ->groupBy('artisan_id')
            ->pluck('total', 'artisan_id');

        return response()->json([
            'data' => $comptes->map(fn (User $compte) => [
                'id' => $compte->id,
                'nom' => $compte->nom,
                'prenom' => $compte->prenom,
                'avatarUrl' => $compte->avatar_url,
                'role' => $compte->role,
                'estPrincipal' => ! $compte->estProfilRattache(),
                'estActif' => $compte->id === $utilisateur->id,
                'suspendu' => $compte->statut === User::STATUT_SUSPENDU,
                'notificationsNonLues' => (int) ($nonLues[$compte->id] ?? 0),
            ])->values(),
            'peutAjouter' => $this->peutAjouter($utilisateur, $comptes->count()),
            'maximum' => User::MAX_COMPTES,
        ]);
    }

    /**
     * Ajoute un profil artisan, puis bascule dessus.
     *
     * Seul le nom est demandé : téléphone, email et ville viennent du compte
     * principal, déjà vérifiés à l'inscription.
     */
    public function store(Request $request): JsonResponse
    {
        $donnees = $request->validate([
            'nom' => 'required|string|max:255',
            'prenom' => 'sometimes|nullable|string|max:255',
        ]);

        $principal = $request->user()->titulaire();

        if (! $principal->estArtisan()) {
            return response()->json([
                'message' => 'Seul un compte artisan peut créer plusieurs profils.',
            ], 403);
        }

        $profil = DB::transaction(function () use ($principal, $donnees) {
            // Verrou sur le compte principal : deux ajouts simultanés ne
            // doivent pas dépasser la limite ensemble.
            User::whereKey($principal->id)->lockForUpdate()->first();

            if ($principal->famille()->count() >= User::MAX_COMPTES) {
                return null;
            }

            $profil = User::create([
                'compte_principal_id' => $principal->id,
                'nom' => $donnees['nom'],
                'prenom' => $donnees['prenom'] ?? null,
                'telephone' => $principal->telephone,
                'email' => $principal->email,
                'ville' => $principal->ville,
                'quartier' => $principal->quartier,
                // Jamais utilisé : on ne se connecte pas à un profil rattaché.
                'password' => Hash::make(Str::random(64)),
                'role' => User::ROLE_ARTISAN,
                'statut' => User::STATUT_ACTIF,
                'is_verified' => true,
            ]);

            Artisan::creerFicheProvisoire($profil);

            return $profil;
        });

        if (! $profil) {
            return response()->json([
                'message' => 'Vous avez atteint le maximum de '.User::MAX_COMPTES.' comptes.',
            ], 422);
        }

        return response()->json($this->ouvrirSession($request, $profil, 'Compte créé.'), 201);
    }

    /** Passe sur un autre compte de la même personne. */
    public function basculer(Request $request, int $compte): JsonResponse
    {
        $cible = $request->user()->famille()->whereKey($compte)->first();

        // Un compte d'une autre personne répond comme un compte inexistant.
        if (! $cible) {
            return response()->json(['message' => 'Compte introuvable.'], 404);
        }

        if ($cible->statut === User::STATUT_SUSPENDU || $cible->titulaire()->statut === User::STATUT_SUSPENDU) {
            return response()->json(['message' => 'Ce compte est suspendu. Contactez le support Maboko.'], 403);
        }

        return response()->json($this->ouvrirSession($request, $cible, 'Compte changé.'));
    }

    // ------------------------------------------------------------------

    /**
     * Le jeton du compte quitté est révoqué : le téléphone ne garde qu'une
     * session à la fois, et revenir en arrière passe par la même route.
     */
    private function ouvrirSession(Request $request, User $compte, string $message): array
    {
        // Une session de navigateur n'a pas de jeton enregistré à révoquer.
        $jeton = $request->user()->currentAccessToken();

        if ($jeton instanceof PersonalAccessToken) {
            $jeton->delete();
        }

        $compte->forceFill(['derniere_connexion_at' => now()])->save();

        return [
            'message' => $message,
            'token' => $compte->createToken('auth_token')->plainTextToken,
            'user' => $compte->fresh(),
        ];
    }

    private function peutAjouter(User $utilisateur, int $nombre): bool
    {
        return $utilisateur->titulaire()->estArtisan() && $nombre < User::MAX_COMPTES;
    }
}
