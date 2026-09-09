<?php

namespace App\Services\Sms;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Envoi reel via l'API REST Twilio. Aucun paquet supplementaire requis :
 * l'API se resume a un POST authentifie en Basic Auth.
 */
class TwilioSmsSender implements SmsSender
{
    public function send(string $telephone, string $message): bool
    {
        $sid = config('sms.twilio.sid');
        $token = config('sms.twilio.token');
        $from = config('sms.twilio.from');

        if (! $sid || ! $token || ! $from) {
            Log::error('[SMS] Configuration Twilio incomplete, envoi abandonne.');

            return false;
        }

        try {
            $response = Http::withBasicAuth($sid, $token)
                ->asForm()
                ->timeout(10)
                ->post("https://api.twilio.com/2010-04-01/Accounts/{$sid}/Messages.json", [
                    'To' => $telephone,
                    'From' => $from,
                    'Body' => $message,
                ]);

            if ($response->failed()) {
                Log::error('[SMS] Echec Twilio', ['statut' => $response->status(), 'corps' => $response->body()]);

                return false;
            }

            return true;
        } catch (\Throwable $e) {
            Log::error('[SMS] Exception Twilio : '.$e->getMessage());

            return false;
        }
    }
}
