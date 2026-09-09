<?php

namespace App\Services;

use App\Models\OtpCode;
use App\Services\Sms\SmsSender;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class OtpService
{
    /** Nombre de verifications erronees tolerees avant invalidation du code. */
    public const MAX_TENTATIVES = 5;

    /** Duree de validite du code envoye par SMS, en minutes. */
    public const VALIDITE_CODE_MINUTES = 10;

    /** Duree de validite du jeton remis apres verification, en minutes. */
    public const VALIDITE_JETON_MINUTES = 15;

    public function __construct(private SmsSender $sms) {}

    /**
     * Emet un code pour une inscription en attente : les donnees du futur
     * compte sont stockees temporairement et le compte n'est cree qu'apres
     * verification du code.
     */
    public function emettrePourInscription(array $donnees): string
    {
        $code = $this->genererCode();

        $this->purger($donnees['telephone']);

        OtpCode::create([
            'user_id' => null,
            'telephone' => $donnees['telephone'],
            'nom' => $donnees['nom'],
            'email' => $donnees['email'],
            'password' => Hash::make($donnees['password']),
            'role' => $donnees['role'] ?? 'client',
            'code' => $code,
            'expiration' => now()->addMinutes(self::VALIDITE_CODE_MINUTES),
            'statut' => false,
        ]);

        $this->envoyer($donnees['telephone'], $code, "Bienvenue sur Maboko. Votre code de validation est {$code}.");

        return $code;
    }

    /**
     * Emet un code pour une reinitialisation de mot de passe.
     */
    public function emettrePourReinitialisation(int $userId, string $telephone): string
    {
        $code = $this->genererCode();

        $this->purger($telephone);

        OtpCode::create([
            'user_id' => $userId,
            'telephone' => $telephone,
            'code' => $code,
            'expiration' => now()->addMinutes(self::VALIDITE_CODE_MINUTES),
            'statut' => false,
        ]);

        $this->envoyer($telephone, $code, "Maboko : votre code de reinitialisation est {$code}. Ne le communiquez a personne.");

        return $code;
    }

    /**
     * Verifie un code. Retourne la ligne OTP si le code est bon, null sinon.
     * Chaque echec incremente le compteur de tentatives ; au-dela du seuil
     * le code est invalide, ce qui rend le tirage au sort inexploitable.
     */
    public function verifier(string $telephone, string $code): ?OtpCode
    {
        $otp = OtpCode::where('telephone', $telephone)
            ->where('statut', false)
            ->where('expiration', '>', now())
            ->where('tentatives', '<', self::MAX_TENTATIVES)
            ->latest()
            ->first();

        if (! $otp) {
            return null;
        }

        if (! hash_equals($otp->code, $code)) {
            $otp->increment('tentatives');

            return null;
        }

        return $otp;
    }

    /**
     * Marque le code comme consomme et remet un jeton a usage unique,
     * seul sesame accepte par /reset-password.
     */
    public function emettreJetonReinitialisation(OtpCode $otp): string
    {
        $jeton = Str::random(64);

        $otp->update([
            'statut' => true,
            'reset_token' => hash('sha256', $jeton),
            'reset_token_expiration' => now()->addMinutes(self::VALIDITE_JETON_MINUTES),
            'reset_token_used_at' => null,
        ]);

        return $jeton;
    }

    /**
     * Consomme un jeton de reinitialisation. Retourne la ligne OTP
     * correspondante, ou null si le jeton est inconnu, expire ou deja utilise.
     */
    public function consommerJetonReinitialisation(string $telephone, string $jeton): ?OtpCode
    {
        $otp = OtpCode::where('telephone', $telephone)
            ->where('reset_token', hash('sha256', $jeton))
            ->whereNull('reset_token_used_at')
            ->where('reset_token_expiration', '>', now())
            ->latest()
            ->first();

        if (! $otp) {
            return null;
        }

        $otp->update(['reset_token_used_at' => now()]);

        return $otp;
    }

    /**
     * Le code n'est renvoye dans la reponse HTTP que si le drapeau de
     * developpement est explicitement active. Il vaut false par defaut.
     */
    public function codeExposable(): bool
    {
        return (bool) config('sms.expose_otp_in_response', false);
    }

    private function genererCode(): string
    {
        return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }

    private function purger(string $telephone): void
    {
        OtpCode::where('telephone', $telephone)->where('statut', false)->delete();
    }

    private function envoyer(string $telephone, string $code, string $message): void
    {
        $this->sms->send($telephone, $message);
    }
}
