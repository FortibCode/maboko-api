<?php

namespace App\Http\Controllers;

use App\Models\Transaction;
use App\Services\AbonnementService;
use App\Services\Paiement\RegistrePasserelles;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Notifications de paiement envoyées par les opérateurs.
 *
 * Cet endpoint est public — il est appelé par Airtel et MTN, pas par
 * l'application. C'est donc la signature qui l'authentifie, et rien d'autre :
 * sans elle, n'importe qui pourrait déclarer un abonnement payé.
 */
class PaiementWebhookController extends Controller
{
    public function __construct(
        private RegistrePasserelles $passerelles,
        private AbonnementService $abonnements,
    ) {}

    public function __invoke(Request $request, string $operateur): JsonResponse
    {
        if (! in_array($operateur, [Transaction::OPERATEUR_AIRTEL, Transaction::OPERATEUR_MTN], true)) {
            return response()->json(['message' => 'Opérateur inconnu.'], 404);
        }

        $passerelle = $this->passerelles->pour($operateur);

        if (! $passerelle->signatureValide($request->headers->all(), $request->getContent())) {
            Log::warning('[PAIEMENT] notification à signature invalide', [
                'operateur' => $operateur,
                'ip' => $request->ip(),
            ]);

            return response()->json(['message' => 'Signature invalide.'], 401);
        }

        $donnees = $request->all();
        $reference = $passerelle->referenceDepuisNotification($donnees);

        if (! $reference) {
            return response()->json(['message' => 'Référence absente.'], 422);
        }

        $transaction = Transaction::where('reference_externe', $reference)
            ->orWhere('reference_interne', $reference)
            ->first();

        if (! $transaction) {
            Log::warning('[PAIEMENT] notification sans transaction correspondante', ['reference' => $reference]);

            // 200 volontaire : un 404 pousse les opérateurs à réémettre
            // indéfiniment une notification que nous ne saurons jamais traiter.
            return response()->json(['message' => 'Transaction inconnue, notification ignorée.']);
        }

        $applique = $this->abonnements->appliquerResultat(
            $transaction,
            $passerelle->statutDepuisNotification($donnees),
        );

        return response()->json([
            'message' => $applique ? 'Notification traitée.' : 'Notification déjà traitée.',
        ]);
    }
}
