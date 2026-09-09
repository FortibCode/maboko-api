<?php

namespace App\Services\Sms;

use Illuminate\Support\Facades\Log;

/**
 * Canal de developpement : le SMS est ecrit dans les logs
 * (storage/logs/laravel.log) au lieu d'etre reellement envoye.
 */
class LogSmsSender implements SmsSender
{
    public function send(string $telephone, string $message): bool
    {
        Log::channel(config('logging.default'))->info('[SMS] '.$telephone.' : '.$message);

        return true;
    }
}
