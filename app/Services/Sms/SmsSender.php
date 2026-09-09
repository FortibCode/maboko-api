<?php

namespace App\Services\Sms;

interface SmsSender
{
    /**
     * Envoie un SMS. Retourne false si l'envoi a echoue,
     * afin que l'appelant puisse decider d'un repli.
     */
    public function send(string $telephone, string $message): bool;
}
