<?php

namespace App\Services;

use App\Models\OtpCode;
use App\Services\Sms\SmsSender;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
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

        $this->envoyer(
            $donnees['telephone'],
            $code,
            "Bienvenue sur Maboko. Votre code de validation est {$code}.",
            self::CONTEXTE_INSCRIPTION,
        );

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

        $this->envoyer(
            $telephone,
            $code,
            "Maboko : votre code de reinitialisation est {$code}. Ne le communiquez a personne.",
            self::CONTEXTE_REINITIALISATION,
        );

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
    /**
     * Numero declare comme numero de test.
     *
     * Aucun SMS ne lui est envoye et son code revient dans la reponse : c'est
     * ce qui permet d'essayer l'inscription tant qu'aucune passerelle n'est
     * branchee, sans exposer les codes de tous les autres comptes.
     */
    public function estNumeroDeTest(?string $telephone): bool
    {
        if ($telephone === null || $telephone === '') {
            return false;
        }

        /** @var list<string> $numeros */
        $numeros = config('sms.numeros_test', []);

        // La comparaison porte sur les neuf derniers chiffres : la variable
        // d'environnement peut etre ecrite « +242061234567 », « 061234567 »
        // ou avec des espaces, elle designera le meme abonne. Sans cela, un
        // numero de test mal recopie echoue sans rien dire.
        $reference = $this->chiffresSignificatifs($telephone);

        foreach ($numeros as $numero) {
            if ($this->chiffresSignificatifs($numero) === $reference) {
                return true;
            }
        }

        return false;
    }

    /** Neuf derniers chiffres d'un numero, indicatif et separateurs retires. */
    private function chiffresSignificatifs(string $telephone): string
    {
        $chiffres = preg_replace('/\D+/', '', $telephone) ?? '';

        return mb_substr($chiffres, -9);
    }

    /** Le code accompagne la creation d'un compte. */
    public const CONTEXTE_INSCRIPTION = 'inscription';

    /** Le code ouvre le changement de mot de passe d'un compte existant. */
    public const CONTEXTE_REINITIALISATION = 'reinitialisation';

    /**
     * Le code peut-il voyager dans la reponse HTTP ?
     *
     * Les deux contextes ne portent pas le meme risque, et c'est ce qui
     * decide ici :
     *
     * - a l'inscription, exposer le code permet de creer un compte avec un
     *   numero qu'on ne possede pas. Genant, reversible, acceptable le temps
     *   d'une phase de test ouverte.
     * - a la reinitialisation, il donne acces a un compte existant — celui de
     *   l'administration comprise. C'est une prise de controle, jamais
     *   acceptable ailleurs qu'en developpement.
     *
     * Le drapeau OTP_EXPOSE_IN_RESPONSE n'agit donc que sur l'inscription des
     * qu'on quitte le poste de developpement.
     */
    public function codeExposable(
        ?string $telephone = null,
        string $contexte = self::CONTEXTE_INSCRIPTION,
    ): bool {
        if ($this->estNumeroDeTest($telephone)) {
            return true;
        }

        if (! config('sms.expose_otp_in_response', false)) {
            return false;
        }

        if ($contexte === self::CONTEXTE_REINITIALISATION) {
            return in_array(config('app.env'), ['local', 'testing'], true);
        }

        return true;
    }

    private function genererCode(): string
    {
        return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }

    private function purger(string $telephone): void
    {
        OtpCode::where('telephone', $telephone)->where('statut', false)->delete();
    }

    private function envoyer(
        string $telephone,
        string $code,
        string $message,
        string $contexte,
    ): void {
        // Quand le code voyage dans la reponse — numero de test, ou phase de
        // test ouverte — il n'y a pas de SMS a tenter, et donc aucune raison
        // d'exiger une passerelle.
        if ($this->codeExposable($telephone, $contexte)) {
            Log::info("[OTP] Code {$code} rendu dans la reponse pour {$telephone}, aucun SMS envoye.");

            return;
        }

        $this->sms->send($telephone, $message);
    }
}
