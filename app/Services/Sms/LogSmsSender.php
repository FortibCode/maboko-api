<?php

namespace App\Services\Sms;

use App\Exceptions\PasserelleSmsAbsente;
use Illuminate\Support\Facades\Log;

/**
 * Canal de developpement : le SMS est ecrit dans les logs
 * (storage/logs/laravel.log) au lieu d'etre reellement envoye.
 */
class LogSmsSender implements SmsSender
{
    public function send(string $telephone, string $message): bool
    {
        // Ce canal n'a de sens qu'en developpement et dans les tests : le
        // code part dans un fichier de journal que personne ne lit depuis un
        // telephone. Partout ailleurs — production, preproduction — mieux
        // vaut un echec visible qu'une inscription qui attend indefiniment un
        // SMS qui n'arrivera jamais.
        if (! in_array(config('app.env'), ['local', 'testing'], true)) {
            Log::error(
                '[SMS] Aucune passerelle configuree : le code destine a '
                .$telephone." n'a pas ete envoye. Renseignez SMS_DRIVER et les "
                .'identifiants de la passerelle.'
            );

            throw new PasserelleSmsAbsente;
        }

        Log::channel(config('logging.default'))->info('[SMS] '.$telephone.' : '.$message);

        return true;
    }
}
