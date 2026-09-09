<?php

namespace App\Services;

use App\Models\Appareil;
use App\Models\Notification;
use App\Models\User;
use App\Services\Push\EnvoyeurPush;
use App\Services\Sms\SmsSender;
use Illuminate\Support\Facades\Log;

/**
 * Envoi des notifications aux utilisateurs.
 *
 * Trois canaux enchaînés :
 *   1. la notification est enregistrée en base, pour l'historique in-app ;
 *   2. elle part en push vers les appareils du destinataire ;
 *   3. si le push échoue et que la notification est importante, un SMS de
 *      secours prend le relais — c'est l'exigence du §7.2, pensée pour une
 *      connexion internet défaillante.
 */
class ServiceNotification
{
    public function __construct(
        private EnvoyeurPush $push,
        private SmsSender $sms,
    ) {}

    /**
     * @param  array<string, string|int>  $donnees  charge utile de navigation
     */
    public function notifier(
        User $destinataire,
        string $titre,
        string $corps,
        string $type = 'generique',
        array $donnees = [],
        bool $important = false,
    ): Notification {
        $notification = Notification::create([
            'artisan_id' => $destinataire->id,
            'title' => $titre,
            'body' => $corps,
            'type' => $type,
            'canal' => 'push',
            'donnees' => $donnees,
            'envoye_at' => now(),
        ]);

        $jetons = Appareil::where('user_id', $destinataire->id)->pluck('jeton_push')->all();

        if (empty($jetons)) {
            $this->replierSurSms($notification, $destinataire, $titre, $corps, $important);

            return $notification;
        }

        // FCM refuse les valeurs non textuelles : la conversion se fait ici,
        // une bonne fois, plutôt qu'à chaque appelant.
        $charge = array_map(
            static fn (string|int $valeur): string => (string) $valeur,
            array_merge($donnees, ['type' => $type]),
        );

        $resultat = $this->push->envoyer($jetons, $titre, $corps, $charge);

        // Un jeton refusé par Firebase ne sera jamais valide à nouveau.
        if (! empty($resultat['jetons_invalides'])) {
            Appareil::whereIn('jeton_push', $resultat['jetons_invalides'])->delete();
        }

        if ($resultat['envoyes'] === 0) {
            $this->replierSurSms($notification, $destinataire, $titre, $corps, $important);
        }

        return $notification->fresh();
    }

    /**
     * Le SMS coûte cher : il ne prend le relais que pour les notifications
     * importantes — une mission reçue, une course proposée, un paiement —
     * jamais pour un like ou un commentaire.
     */
    private function replierSurSms(
        Notification $notification,
        User $destinataire,
        string $titre,
        string $corps,
        bool $important,
    ): void {
        if (! $important || ! config('push.repli_sms') || ! $destinataire->telephone) {
            return;
        }

        $envoye = $this->sms->send($destinataire->telephone, "Maboko — {$titre} : {$corps}");

        $notification->update([
            'canal' => 'sms',
            'repli_sms_envoye' => $envoye,
        ]);

        if (! $envoye) {
            Log::warning('[NOTIFICATION] push et SMS ont tous deux échoué', [
                'utilisateur' => $destinataire->id,
                'type' => $notification->type,
            ]);
        }
    }
}
