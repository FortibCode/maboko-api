<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\OtpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class OtpController extends Controller
{
    public function __construct(private OtpService $otp) {}

    /**
     * Inscription, etape 1 : le formulaire est mis en attente et un code
     * est envoye par SMS. Aucun compte n'est cree a ce stade.
     */
    public function sendRegisterOtp(Request $request): JsonResponse
    {
        $donnees = $request->validate([
            'nom' => 'required|string|max:255',
            'email' => 'required|email|max:255|unique:users,email',
            'telephone' => ['required', 'string', 'regex:/^\+2420[456]\d{7}$/', 'unique:users,telephone'],
            'password' => 'required|string|min:8',
            'role' => ['sometimes', 'string', Rule::in(User::ROLES)],
        ]);

        $code = $this->otp->emettrePourInscription($donnees);

        return response()->json($this->reponseAvecCode(
            'Code de validation envoye par SMS.',
            $code,
            $donnees['telephone'],
        ));
    }

    /**
     * Inscription, etape 2 : le code est verifie et le compte est cree
     * a partir des donnees mises en attente a l'etape 1. Les valeurs
     * renvoyees par le client ne sont pas reprises, pour qu'il ne puisse
     * pas modifier son role ou son e-mail entre les deux appels.
     */
    public function verifyAndRegister(Request $request): JsonResponse
    {
        $request->validate([
            'telephone' => 'required|string',
            'code' => 'required|string|size:6',
        ]);

        $otp = $this->otp->verifier($request->telephone, $request->code);

        if (! $otp || ! $otp->email) {
            return response()->json(['message' => 'Code invalide ou expire.'], 422);
        }

        $user = User::create([
            'nom' => $otp->nom,
            'email' => $otp->email,
            'telephone' => $otp->telephone,
            'password' => $otp->password,
            'role' => $otp->role ?: User::ROLE_CLIENT,
            'is_verified' => true,
        ]);

        $otp->update(['user_id' => $user->id, 'statut' => true]);

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'message' => 'Compte cree avec succes.',
            'token' => $token,
            'user' => $user,
        ], 201);
    }

    /**
     * Mot de passe oublie, etape 1 : envoi du code.
     * La reponse est identique que le numero existe ou non, afin de ne pas
     * transformer cet endpoint en annuaire des comptes inscrits.
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
            'Si un compte existe avec ce numero, un code vient de lui etre envoye.',
            $code,
            $request->telephone,
        ));
    }

    /**
     * Mot de passe oublie, etape 2 : la verification du code remet un jeton
     * a usage unique, seul sesame accepte ensuite par /reset-password.
     */
    public function verifyOtp(Request $request): JsonResponse
    {
        $request->validate([
            'telephone' => 'required|string',
            'code' => 'required|string|size:6',
        ]);

        $otp = $this->otp->verifier($request->telephone, $request->code);

        if (! $otp) {
            return response()->json(['message' => 'Code invalide ou expire.'], 422);
        }

        return response()->json([
            'message' => 'Code verifie.',
            'reset_token' => $this->otp->emettreJetonReinitialisation($otp),
            'expire_dans' => OtpService::VALIDITE_JETON_MINUTES * 60,
        ]);
    }

    /**
     * En developpement sans passerelle SMS, le code peut etre renvoye dans la
     * reponse. Le drapeau vaut false par defaut et doit le rester en production.
     */
    private function reponseAvecCode(string $message, ?string $code, ?string $telephone = null): array
    {
        $reponse = ['message' => $message];

        if ($code !== null && $this->otp->codeExposable($telephone)) {
            $reponse['debug_code'] = $code;
        }

        return $reponse;
    }
}
