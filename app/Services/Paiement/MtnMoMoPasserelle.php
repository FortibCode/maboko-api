<?php

namespace App\Services\Paiement;

use App\Models\Transaction;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * MTN Mobile Money — API « Collection » (§6.2).
 *
 * Non éprouvée : elle demande un compte marchand MTN. La structure suit la
 * documentation MoMo Developer ; l'identifiant de référence est un UUID
 * généré par nos soins, que l'opérateur nous renvoie ensuite.
 */
class MtnMoMoPasserelle implements PasserellePaiement
{
    public function initier(Transaction $transaction, string $telephone): ResultatPaiement
    {
        $jeton = $this->jetonAcces();

        if (! $jeton) {
            return ResultatPaiement::echoue('Service MTN Mobile Money indisponible.');
        }

        // MoMo identifie la demande par un UUID que nous choisissons.
        $reference = (string) Str::uuid();

        try {
            $reponse = Http::withToken($jeton)
                ->withHeaders([
                    'X-Reference-Id' => $reference,
                    'X-Target-Environment' => config('paiement.mtn.environnement'),
                    'Ocp-Apim-Subscription-Key' => config('paiement.mtn.cle_abonnement'),
                ])
                ->timeout(30)
                ->post(config('paiement.mtn.base_url').'/collection/v1_0/requesttopay', [
                    'amount' => (string) (int) $transaction->montant,
                    'currency' => config('paiement.mtn.devise'),
                    'externalId' => $transaction->reference_interne,
                    'payer' => [
                        'partyIdType' => 'MSISDN',
                        'partyId' => ltrim($telephone, '+'),
                    ],
                    'payerMessage' => 'Abonnement Maboko',
                    'payeeNote' => $transaction->reference_interne,
                ]);

            if ($reponse->failed()) {
                Log::error('[PAIEMENT mtn] échec', ['statut' => $reponse->status(), 'corps' => $reponse->body()]);

                return ResultatPaiement::echoue('Le paiement MTN Mobile Money a été refusé.');
            }

            // MoMo répond 202 : le client doit saisir son code sur le téléphone.
            return ResultatPaiement::enAttente($reference, ['reference_momo' => $reference]);
        } catch (\Throwable $e) {
            Log::error('[PAIEMENT mtn] exception : '.$e->getMessage());

            return ResultatPaiement::echoue('MTN Mobile Money est momentanément injoignable.');
        }
    }

    public function verifier(Transaction $transaction): ResultatPaiement
    {
        $jeton = $this->jetonAcces();

        if (! $jeton || ! $transaction->reference_externe) {
            return ResultatPaiement::enAttente($transaction->reference_externe);
        }

        try {
            $reponse = Http::withToken($jeton)
                ->withHeaders([
                    'X-Target-Environment' => config('paiement.mtn.environnement'),
                    'Ocp-Apim-Subscription-Key' => config('paiement.mtn.cle_abonnement'),
                ])
                ->timeout(20)
                ->get(config('paiement.mtn.base_url').'/collection/v1_0/requesttopay/'.$transaction->reference_externe);

            return $this->statutDepuisNotification($reponse->json() ?? []);
        } catch (\Throwable $e) {
            return ResultatPaiement::enAttente($transaction->reference_externe);
        }
    }

    public function signatureValide(array $entetes, string $corps): bool
    {
        $secret = config('paiement.mtn.secret_webhook');

        if (! $secret) {
            Log::warning('[PAIEMENT mtn] notification rejetée : aucun secret configuré.');

            return false;
        }

        $signature = $entetes['x-signature'][0] ?? '';

        return hash_equals(hash_hmac('sha256', $corps, $secret), $signature);
    }

    public function referenceDepuisNotification(array $donnees): ?string
    {
        return $donnees['referenceId'] ?? $donnees['financialTransactionId'] ?? null;
    }

    public function statutDepuisNotification(array $donnees): ResultatPaiement
    {
        return match (strtoupper((string) ($donnees['status'] ?? ''))) {
            'SUCCESSFUL' => ResultatPaiement::reussi($donnees['financialTransactionId'] ?? null, $donnees),
            'FAILED', 'REJECTED', 'TIMEOUT' => ResultatPaiement::echoue(
                $donnees['reason'] ?? 'Paiement refusé.',
                $donnees,
            ),
            default => ResultatPaiement::enAttente($donnees['referenceId'] ?? null, $donnees),
        };
    }

    private function jetonAcces(): ?string
    {
        return Cache::remember('mtn.jeton', now()->addMinutes(50), function () {
            try {
                $reponse = Http::withBasicAuth(
                    config('paiement.mtn.utilisateur_api'),
                    config('paiement.mtn.cle_api'),
                )
                    ->withHeaders(['Ocp-Apim-Subscription-Key' => config('paiement.mtn.cle_abonnement')])
                    ->timeout(20)
                    ->post(config('paiement.mtn.base_url').'/collection/token/');

                return $reponse->successful() ? $reponse->json('access_token') : null;
            } catch (\Throwable $e) {
                Log::error('[PAIEMENT mtn] jeton indisponible : '.$e->getMessage());

                return null;
            }
        });
    }
}
