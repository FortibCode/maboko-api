<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Auth\VerificateurJetonGoogle;
use App\Services\MediaService;
use App\Services\OtpService;
use App\Services\SuppressionCompte;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use RuntimeException;

class AuthController extends Controller
{
    public function __construct(private OtpService $otp, private MediaService $media) {}

    /**
     * Connexion par e-mail ou par numero de telephone.
     */
    public function login(Request $request): JsonResponse
    {
        $request->validate([
            'identifiant' => 'required_without:email|string',
            'email' => 'required_without:identifiant|string',
            'password' => 'required|string',
        ]);

        // Le champ historique s'appelle "email" mais accepte aussi le telephone.
        $identifiant = $request->input('identifiant', $request->input('email'));

        $user = User::where('email', $identifiant)
            ->orWhere('telephone', $identifiant)
            ->first();

        if (! $user || ! Hash::check($request->password, $user->password)) {
            return response()->json(['message' => 'Identifiants invalides.'], 401);
        }

        if ($user->statut === User::STATUT_SUSPENDU) {
            return response()->json(['message' => 'Ce compte est suspendu. Contactez le support Maboko.'], 403);
        }

        $user->forceFill(['derniere_connexion_at' => now()])->save();

        return response()->json([
            'message' => 'Connexion reussie.',
            'token' => $user->createToken('auth_token')->plainTextToken,
            'user' => $user,
        ]);
    }

    /**
     * Connexion ou inscription automatique via Google OAuth (§7.1).
     *
     * Le client transmet uniquement le jeton d'identité (ID token) délivré
     * par le SDK Google Sign-In. C'est sa signature, vérifiée côté serveur,
     * qui prouve l'identité — jamais des champs « email »/« google_id »
     * fournis en clair : un simple POST avec l'adresse d'un tiers aurait
     * sinon suffi à obtenir un jeton d'accès valide pour son compte.
     */
    public function loginGoogle(Request $request, VerificateurJetonGoogle $verificateur): JsonResponse
    {
        $request->validate([
            'id_token' => 'required|string',
        ]);

        $identite = $verificateur->verifier($request->string('id_token')->value());

        if ($identite === null) {
            return response()->json(['message' => 'Jeton Google invalide ou non vérifié.'], 401);
        }

        $user = User::where('email', $identite['email'])->first();

        if (! $user) {
            $user = User::create([
                'email' => $identite['email'],
                // Google Sign-In ne fournit pas de numéro de téléphone : ce
                // repère est un espace réservé, à compléter par l'utilisateur
                // avant d'utiliser Allô Chauffeur ou de recevoir un SMS.
                'telephone' => $this->telephonePlaceholderUnique(),
                'nom' => $identite['nom'] ?: 'Utilisateur',
                'prenom' => $identite['prenom'],
                'avatar_url' => $identite['avatarUrl'],
                'password' => Hash::make(Str::random(40)),
                'role' => User::ROLE_CLIENT,
                'statut' => User::STATUT_ACTIF,
                'is_verified' => true,
            ]);
        }

        if ($user->statut === User::STATUT_SUSPENDU) {
            return response()->json(['message' => 'Ce compte est suspendu. Contactez le support Maboko.'], 403);
        }

        $user->forceFill(['derniere_connexion_at' => now()])->save();

        return response()->json([
            'message' => 'Connexion Google réussie.',
            'token' => $user->createToken('auth_token')->plainTextToken,
            'user' => $user,
        ]);
    }

    /**
     * Numéro d'espace réservé pour un compte créé via Google, où la colonne
     * est obligatoire et unique. La collision est improbable mais vérifiée :
     * mieux vaut une boucle courte qu'une contrainte d'unicité violée en
     * production.
     */
    private function telephonePlaceholderUnique(): string
    {
        do {
            $candidat = '+24200'.random_int(1000000, 9999999);
        } while (User::where('telephone', $candidat)->exists());

        return $candidat;
    }

    /**
     * Reinitialisation du mot de passe.
     *
     * Exige le jeton a usage unique remis par /verify-otp apres verification
     * du code SMS. Sans ce jeton, connaitre un numero de telephone ne permet
     * plus de changer le mot de passe associe.
     */
    public function resetPassword(Request $request): JsonResponse
    {
        $request->validate([
            'telephone' => 'required|string',
            'reset_token' => 'required|string',
            'password' => 'required|string|min:8|confirmed',
        ]);

        $otp = $this->otp->consommerJetonReinitialisation($request->telephone, $request->reset_token);

        if (! $otp) {
            return response()->json([
                'message' => 'Demande de reinitialisation invalide ou expiree. Recommencez la procedure.',
            ], 422);
        }

        $user = User::where('telephone', $request->telephone)->first();

        if (! $user) {
            return response()->json(['message' => 'Utilisateur introuvable.'], 404);
        }

        $user->update(['password' => Hash::make($request->password)]);

        // Toute session ouverte ailleurs est revoquee : si le compte etait
        // compromis, le changement de mot de passe en reprend le controle.
        $user->tokens()->delete();

        return response()->json(['message' => 'Mot de passe modifie. Reconnectez-vous.']);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Deconnexion reussie.']);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json($request->user());
    }

    /**
     * Photo de profil de l'utilisateur connecte.
     *
     * La colonne « avatar_url » existait depuis le debut mais n'etait jamais
     * ecrite : aucune route ne permettait de la remplir. L'application se
     * rabattait donc sur une icone choisie dans une liste figee, identique
     * pour tous les comptes ayant pris le meme rang.
     *
     * L'image arrive en base64, comme pour les publications et les pieces
     * d'identite, et passe par le meme service : formats JPEG/PNG/WebP,
     * 5 Mo maximum.
     */
    public function enregistrerAvatar(Request $request): JsonResponse
    {
        $request->validate([
            'photo' => 'required|string',
        ]);

        try {
            $url = $this->media->enregistrerImage($request->string('photo')->value(), 'avatars');
        } catch (RuntimeException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'errors' => ['photo' => [$e->getMessage()]],
            ], 422);
        }

        $utilisateur = $request->user();
        $utilisateur->update(['avatar_url' => $url]);

        return response()->json([
            'message' => 'Photo de profil mise a jour.',
            // Le client affiche cette URL immediatement, sans relire le
            // compte : elle doit etre absolue comme partout ailleurs.
            'avatarUrl' => $utilisateur->avatar_url,
        ]);
    }

    /**
     * Modification des informations du compte.
     *
     * L'entree « Modifier mon profil » existait dans les parametres sans
     * aucune action, et aucune route ne permettait de changer son nom ou sa
     * ville : les valeurs saisies a l'inscription etaient definitives.
     *
     * Le role, le statut et le telephone ne sont pas modifiables ici : le
     * telephone identifie le compte et sert a la recuperation de mot de passe.
     */
    public function modifierProfil(Request $request): JsonResponse
    {
        $utilisateur = $request->user();

        $donnees = $request->validate([
            'nom' => 'sometimes|required|string|max:255',
            'prenom' => 'sometimes|nullable|string|max:255',
            'email' => [
                'sometimes', 'required', 'email', 'max:255',
                Rule::unique('users', 'email')->ignore($utilisateur->id),
            ],
            'ville' => 'sometimes|nullable|string|max:120',
            'quartier' => 'sometimes|nullable|string|max:120',
        ]);

        $utilisateur->update($donnees);

        return response()->json([
            'message' => 'Profil mis a jour.',
            'user' => $utilisateur->fresh(),
        ]);
    }

    /** Retrait de la photo de profil. */
    public function supprimerAvatar(Request $request): JsonResponse
    {
        $request->user()->update(['avatar_url' => null]);

        return response()->json(['message' => 'Photo de profil retiree.', 'avatarUrl' => null]);
    }

    /**
     * Suppression du compte, à la demande de son titulaire.
     *
     * Le mot de passe est redemandé : un téléphone laissé déverrouillé ne doit
     * pas suffire à effacer un compte.
     */
    public function supprimerCompte(Request $request, SuppressionCompte $suppression): JsonResponse
    {
        $request->validate([
            'password' => 'required|string',
            'confirmation' => 'required|accepted',
        ], [
            'confirmation.accepted' => 'Confirmez que vous souhaitez supprimer définitivement votre compte.',
        ]);

        $utilisateur = $request->user();

        if (! Hash::check($request->password, $utilisateur->password)) {
            return response()->json(['message' => 'Mot de passe incorrect.'], 422);
        }

        $suppression->supprimer($utilisateur);

        return response()->json([
            'message' => 'Votre compte a été supprimé. Vos données personnelles ont été effacées.',
        ]);
    }
}
