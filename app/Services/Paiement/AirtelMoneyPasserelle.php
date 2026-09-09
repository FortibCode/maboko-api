<?php

namespace App\Services\Paiement;

use App\Models\Transaction;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Airtel Money — API « Collections » (§6.2).
 *
 * Non éprouvée : elle demande un compte marchand Airtel, dont l'ouverture
 * prend plusieurs semaines. La structure suit la documentation publique de
 * l'API ; les identifiants et le format exact de la notification sont à
 * confirmer à la mise en service.
 */
class AirtelMoneyPasserelle implements PasserellePaiement
{
    public function initier(Transaction $transaction, string $telephone): ResultatPaiement
    {
        $jeton = $this->jetonAcces();

        if (! $jeton) {
            return ResultatPaiement::echoue('Service Airtel Money indisponible.');
        }

        try {
            $reponse = Http::withToken($jeton)
                ->withHeaders([
                    'X-Country' => config('paiement.airtel.pays'),
                    'X-Currency' => config('paiement.airtel.devise'),
                ])
                ->timeout(30)
                ->post(config('paiement.airtel.base_url').'/merchant/v1/payments/', [
                    'reference' => $transaction->reference_interne,
                    'subscriber' => [
                        'country' => config('paiement.airtel.pays'),
                        'currency' => config('paiement.airtel.devise'),
                        // L'API attend le numéro national, sans indicatif.
                        'msisdn' => $this->numeroNational($telephone),
                    ],
                    'transaction' => [
                        'amount' => (float) $transaction->montant,
                        'country' => config('paiement.airtel.pays'),
                        'currency' => config('paiement.airtel.devise'),
                        'id' => $transaction->reference_interne,
                    ],
                ]);

            if ($reponse->failed()) {
                Log::error('[PAIEMENT airtel] échec', ['statut' => $reponse->status(), 'corps' => $reponse->body()]);

                return ResultatPaiement::echoue('Le paiement Airtel Money a été refusé.', $reponse->json() ?? []);
            }

            $donnees = $reponse->json();

            // Le client doit confirmer sur son téléphone : la notification
            // arrivera par webhook.
            return ResultatPaiement::enAttente(
                $donnees['data']['transaction']['id'] ?? $transaction->reference_interne,
                $donnees,
            );
        } catch (\Throwable $e) {
            Log::error('[PAIEMENT airtel] exception : '.$e->getMessage());

            return ResultatPaiement::echoue('Airtel Money est momentanément injoignable.');
        }
    }

    public function verifier(Transaction $transaction): ResultatPaiement
    {
        $jeton = $this->jetonAcces();

        if (! $jeton) {
            return ResultatPaiement::enAttente($transaction->reference_externe);
        }

        try {
            $reponse = Http::withToken($jeton)
                ->withHeaders([
                    'X-Country' => config('paiement.airtel.pays'),
                    'X-Currency' => config('paiement.airtel.devise'),
                ])
                ->timeout(20)
                ->get(config('paiement.airtel.base_url').'/standard/v1/payments/'.$transaction->reference_interne);

            return $this->statutDepuisNotification($reponse->json() ?? []);
        } catch (\Throwable $e) {
            return ResultatPaiement::enAttente($transaction->reference_externe);
        }
    }

    public function signatureValide(array $entetes, string $corps): bool
    {
        $secret = config('paiement.airtel.secret_webhook');

        // Sans secret configuré, aucune notification n'est acceptée : mieux
        // vaut refuser un paiement que d'en enregistrer un inventé.
        if (! $secret) {
            Log::warning('[PAIEMENT airtel] notification rejetée : aucun secret configuré.');

            return false;
        }

        $signature = $entetes['x-auth-token'][0] ?? $entetes['x-signature'][0] ?? '';

        return hash_equals(hash_hmac('sha256', $corps, $secret), $signature);
    }

    public function referenceDepuisNotification(array $donnees): ?string
    {
        return $donnees['transaction']['id'] ?? $donnees['data']['transaction']['id'] ?? null;
    }

    public function statutDepuisNotification(array $donnees): ResultatPaiement
    {
        $transaction = $donnees['transaction'] ?? $donnees['data']['transaction'] ?? [];
        $statut = strtoupper((string) ($transaction['status'] ?? $transaction['status_code'] ?? ''));

        return match (true) {
            in_array($statut, ['TS', 'SUCCESS', 'SUCCESSFUL'], true) => ResultatPaiement::reussi($transaction['id'] ?? null, $donnees),
            in_array($statut, ['TF', 'FAILED', 'TA'], true) => ResultatPaiement::echoue($transaction['message'] ?? 'Paiement refusé.', $donnees),
            default => ResultatPaiement::enAttente($transaction['id'] ?? null, $donnees),
        };
    }

    /** Le jeton OAuth vaut une heure : inutile d'en demander un à chaque appel. */
    private function jetonAcces(): ?string
    {
        return Cache::remember('airtel.jeton', now()->addMinutes(50), function () {
            try {
                $reponse = Http::timeout(20)->post(config('paiement.airtel.base_url').'/auth/oauth2/token', [
                    'client_id' => config('paiement.airtel.client_id'),
                    'client_secret' => config('paiement.airtel.client_secret'),
                    'grant_type' => 'client_credentials',
                ]);

                return $reponse->successful() ? $reponse->json('access_token') : null;
            } catch (\Throwable $e) {
                Log::error('[PAIEMENT airtel] jeton indisponible : '.$e->getMessage());

                return null;
            }
        });
    }

    private function numeroNational(string $telephone): string
    {
        return ltrim(preg_replace('/^\+?242/', '', $telephone) ?? $telephone, '0');
    }
}
