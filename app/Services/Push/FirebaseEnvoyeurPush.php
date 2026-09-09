<?php

namespace App\Services\Push;

use Google\Auth\Credentials\ServiceAccountCredentials;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Firebase Cloud Messaging, API HTTP v1 (§6.2).
 *
 * Non éprouvée : elle demande un projet Firebase et un fichier de compte de
 * service. Les jetons refusés par Firebase sont remontés à l'appelant, qui
 * les retire de la base — sans quoi la liste d'appareils se remplit de jetons
 * morts et chaque envoi devient plus lent.
 */
class FirebaseEnvoyeurPush implements EnvoyeurPush
{
    public function envoyer(array $jetons, string $titre, string $corps, array $donnees = []): array
    {
        $projet = config('push.firebase.projet_id');
        $jetonAcces = $this->jetonAcces();

        if (! $projet || ! $jetonAcces) {
            Log::error('[PUSH] configuration Firebase incomplète, envoi abandonné.');

            return ['envoyes' => 0, 'jetons_invalides' => []];
        }

        $envoyes = 0;
        $invalides = [];

        foreach ($jetons as $jeton) {
            try {
                $reponse = Http::withToken($jetonAcces)
                    ->timeout(15)
                    ->post("https://fcm.googleapis.com/v1/projects/{$projet}/messages:send", [
                        'message' => [
                            'token' => $jeton,
                            'notification' => ['title' => $titre, 'body' => $corps],
                            // Les valeurs doivent être des chaînes : FCM refuse
                            // silencieusement les entiers et les booléens.
                            'data' => array_map(fn ($v) => (string) $v, $donnees),
                            'android' => ['priority' => 'high'],
                        ],
                    ]);

                if ($reponse->successful()) {
                    $envoyes++;

                    continue;
                }

                // 404 UNREGISTERED / 400 INVALID_ARGUMENT : le jeton est mort.
                if (in_array($reponse->status(), [400, 404], true)) {
                    $invalides[] = $jeton;
                }

                Log::warning('[PUSH] échec Firebase', ['statut' => $reponse->status(), 'corps' => $reponse->body()]);
            } catch (\Throwable $e) {
                Log::error('[PUSH] exception Firebase : '.$e->getMessage());
            }
        }

        return ['envoyes' => $envoyes, 'jetons_invalides' => $invalides];
    }

    /** Le jeton OAuth vaut une heure. */
    private function jetonAcces(): ?string
    {
        $chemin = config('push.firebase.identifiants');

        if (! $chemin || ! is_file($chemin)) {
            return null;
        }

        return Cache::remember('firebase.jeton', now()->addMinutes(50), function () use ($chemin) {
            try {
                $identifiants = new ServiceAccountCredentials(
                    'https://www.googleapis.com/auth/firebase.messaging',
                    $chemin,
                );

                return $identifiants->fetchAuthToken()['access_token'] ?? null;
            } catch (\Throwable $e) {
                Log::error('[PUSH] jeton Firebase indisponible : '.$e->getMessage());

                return null;
            }
        });
    }
}
