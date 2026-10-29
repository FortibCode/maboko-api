<?php

namespace App\Http\Controllers;

use App\Models\Artisan;
use App\Models\User;
use App\Services\OtpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class OtpController extends Controller
{
    public function __construct(private OtpService $otp) {}

    /**
     * Inscription, étape 1 : le formulaire est mis en attente et un code
     * est envoyé par SMS. Aucun compte n'est créé à ce stade.
     */
    public function sendRegisterOtp(Request $request): JsonResponse
    {
        // 🧹 NETTOYAGE : si un compte soft-deleted existe avec ce téléphone
        // ou cet email, on le supprime définitivement pour libérer la place.
        $telephone = $request->input('telephone');
        $email = $request->input('email');

        if ($telephone || $email) {
            $supprimes = User::onlyTrashed()
                ->where(function ($q) use ($telephone, $email) {
                    if ($telephone) $q->orWhere('telephone', $telephone);
                    if ($email) $q->orWhere('email', $email);
                })
                ->get();

            foreach ($supprimes as $user) {
                if ($user->artisan) {
                    $user->artisan->metiers()->detach();
                    $user->artisan->badges()->detach();
                    $user->artisan->delete();
                }
                if ($user->chauffeur) {
                    $user->chauffeur->delete();
                }
                $user->forceDelete();
            }
        }

        $donnees = $request->validate([
            'nom' => 'required|string|max:255',
            'email' => 'required|email|max:255|unique:users,email',
            'telephone' => ['required', 'string', 'regex:/^\+2420[456]\d{7}$/', 'unique:users,telephone'],
            'password' => 'required|string|min:8',
            'role' => ['sometimes', 'string', Rule::in(User::ROLES)],
        ]);

        $code = $this->otp->emettrePourInscription($donnees);

        return response()->json($this->reponseAvecCode(
            'Code de validation envoyé par SMS.',
            $code,
            $donnees['telephone'],
        ));
    }

    /**
     * Inscription, étape 2 : le code est vérifié et le compte est créé
     * à partir des données mises en attente à l'étape 1.
     */
    public function verifyAndRegister(Request $request): JsonResponse
    {
        $request->validate([
            'telephone' => 'required|string',
            'code' => 'required|string|size:6',
        ]);

        $otp = $this->otp->verifier($request->telephone, $request->code);

        if (! $otp || ! $otp->email) {
            return response()->json(['message' => 'Code invalide ou expiré.'], 422);
        }

        $user = User::create([
            'nom' => $otp->nom,
            'email' => $otp->email,
            'telephone' => $otp->telephone,
            'password' => $otp->password,
            'role' => $otp->role ?: User::ROLE_CLIENT,
            'is_verified' => true,
        ]);

        // Aucun compte artisan sans fiche : une fiche provisoire est créée
        if ($user->role === User::ROLE_ARTISAN) {
            $ville = $user->ville ?? 'Brazzaville';

            Artisan::create([
                'utilisateur_id' => $user->id,
                'specialite' => 'À compléter',
                'adresse' => $ville.', Congo',
                'latitude' => -4.2634,
                'longitude' => 15.2429,
                'bio' => 'Artisan à '.$ville,
                'zone_intervention' => $ville,
                'rayon_km' => 10,
                'statut_validation' => Artisan::VALIDATION_EN_ATTENTE,
            ]);
        }

        $otp->update(['user_id' => $user->id, 'statut' => true]);

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'message' => 'Compte créé avec succès.',
            'token' => $token,
            'user' => $user,
        ], 201);
    }

    /**
     * Mot de passe oublié, étape 1 : envoi du code.
     */
    public function sendOtp(Request $request): JsonResponse
    {
        $request->validate([
            'telephone' => ['required', 'string', 'regex:/^\+2420[456]\d{7}$/'],
        ]);

        $user = User::where('telephone', $request->telephone)->first();
        $code = null;

        if ($user) {
            $code = $this->otp->emettrePourReinitialisation($user->id, $request->telephone);
        }

        return response()->json($this->reponseAvecCode(
            'Si un compte existe avec ce numéro, un code vient de lui être envoyé.',
            $code,
            $request->telephone,
            OtpService::CONTEXTE_REINITIALISATION,
        ));
    }

    /**
     * Mot de passe oublié, étape 2 : la vérification du code remet un jeton
     * à usage unique, seul sésame accepté ensuite par /reset-password.
     */
    public function verifyOtp(Request $request): JsonResponse
    {
        $request->validate([
            'telephone' => 'required|string',
            'code' => 'required|string|size:6',
        ]);

        $otp = $this->otp->verifier($request->telephone, $request->code);

        if (! $otp) {
            return response()->json(['message' => 'Code invalide ou expiré.'], 422);
        }

        return response()->json([
            'message' => 'Code vérifié.',
            'reset_token' => $this->otp->emettreJetonReinitialisation($otp),
            'expire_dans' => OtpService::VALIDITE_JETON_MINUTES * 60,
        ]);
    }

    /**
     * En développement sans passerelle SMS, le code peut être renvoyé dans la
     * réponse. Le drapeau vaut false par défaut et doit le rester en production.
     */
    private function reponseAvecCode(
        string $message,
        ?string $code,
        ?string $telephone = null,
        string $contexte = OtpService::CONTEXTE_INSCRIPTION,
    ): array {
        $reponse = ['message' => $message];

        if ($code !== null && $this->otp->codeExposable($telephone, $contexte)) {
            $reponse['debug_code'] = $code;
        }

        return $reponse;
    }
}