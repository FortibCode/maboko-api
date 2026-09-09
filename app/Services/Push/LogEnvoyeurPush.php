<?php

namespace App\Services\Push;

use Illuminate\Support\Facades\Log;

/**
 * Canal de développement : la notification part dans les journaux.
 * Permet d'éprouver tout le parcours sans projet Firebase.
 */
class LogEnvoyeurPush implements EnvoyeurPush
{
    public function envoyer(array $jetons, string $titre, string $corps, array $donnees = []): array
    {
        foreach ($jetons as $jeton) {
            Log::info('[PUSH] '.substr($jeton, 0, 12).'… : '.$titre.' — '.$corps, $donnees);
        }

        return ['envoyes' => count($jetons), 'jetons_invalides' => []];
    }
}
