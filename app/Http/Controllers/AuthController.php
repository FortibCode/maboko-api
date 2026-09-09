<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\OtpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function __construct(private OtpService $otp) {}

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
}
