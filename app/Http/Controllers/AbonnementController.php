<?php

namespace App\Http\Controllers;

use App\Http\Resources\PlanResource;
use App\Http\Resources\TransactionResource;
use App\Models\Artisan;
use App\Models\Plan;
use App\Models\Transaction;
use App\Services\AbonnementService;
use App\Services\Paiement\RegistrePasserelles;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * Formules d'abonnement et souscription (§4.5, §5.2.4).
 */
class AbonnementController extends Controller
{
    public function __construct(
        private AbonnementService $abonnements,
        private RegistrePasserelles $passerelles,
    ) {}

    /** Les quatre formules proposées aux artisans. */
    public function plans(): AnonymousResourceCollection
    {
        return PlanResource::collection(
            Plan::where('actif', true)->orderBy('ordre')->get(),
        );
    }

    /** Abonnement en cours de l'artisan connecté. */
    public function actuel(Request $request): JsonResponse
    {
        $artisan = $this->ficheDe($request);

        if (! $artisan) {
            return response()->json(['message' => "Aucune fiche artisan n'est rattachée à ce compte."], 404);
        }

        $abonnement = $artisan->abonnementActif;

        return response()->json([
            'plan' => $abonnement?->plan
                ? new PlanResource($abonnement->plan)
                : new PlanResource(Plan::where('slug', Plan::GRATUIT)->first()),
            'debutLe' => $abonnement?->debut?->toDateString(),
            'finLe' => $abonnement?->fin?->toDateString(),
            'renouvellementAuto' => (bool) ($abonnement->renouvellement_auto ?? false),
        ]);
    }

    public function souscrire(Request $request): JsonResponse
    {
        $artisan = $this->ficheDe($request);

        if (! $artisan) {
            return response()->json(['message' => 'Complétez votre fiche artisan avant de souscrire.'], 422);
        }

        $donnees = $request->validate([
            'plan' => ['required', 'string', 'exists:plans,slug'],
            'periodicite' => ['required', Rule::in(['mensuel', 'annuel'])],
            'operateur' => ['required', Rule::in([Transaction::OPERATEUR_AIRTEL, Transaction::OPERATEUR_MTN])],
            'telephone' => ['required', 'string', 'regex:/^\+2420[456]\d{7}$/'],
        ]);

        $plan = Plan::where('slug', $donnees['plan'])->where('actif', true)->firstOrFail();

        $resultat = $this->abonnements->souscrire(
            $artisan,
            $plan,
            $donnees['periodicite'],
            $donnees['operateur'],
            $donnees['telephone'],
        );

        $transaction = $resultat['transaction'];

        return response()->json([
            'message' => $this->messageDe($transaction),
            'transaction' => $transaction ? new TransactionResource($transaction) : null,
            'abonnementActif' => $resultat['abonnement'] !== null,
        ], $transaction?->statut === Transaction::STATUT_ECHOUEE ? 422 : 201);
    }

    /**
     * Suivi d'une transaction.
     *
     * L'application interroge cet endpoint pendant que le client confirme le
     * paiement sur son téléphone : le webhook peut se perdre, pas la réponse.
     */
    public function suivreTransaction(Request $request, string $reference): JsonResponse
    {
        $transaction = Transaction::where('reference_interne', $reference)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        // Encore en attente : on redemande à l'opérateur plutôt que d'attendre
        // une notification qui n'arrivera peut-être jamais.
        if ($transaction->statut === Transaction::STATUT_EN_ATTENTE) {
            $resultat = $this->passerelles->pour($transaction->operateur)->verifier($transaction);

            if ($resultat->estFinal()) {
                $this->abonnements->appliquerResultat($transaction, $resultat);
                $transaction->refresh();
            }
        }

        return response()->json(['transaction' => new TransactionResource($transaction)]);
    }

    public function transactions(Request $request): AnonymousResourceCollection
    {
        return TransactionResource::collection(
            Transaction::where('user_id', $request->user()->id)
                ->latest()
                ->cursorPaginate(20),
        );
    }

    private function ficheDe(Request $request): ?Artisan
    {
        return Artisan::where('utilisateur_id', $request->user()->id)
            ->with(['badges', 'abonnementActif.plan'])
            ->first();
    }

    private function messageDe(?Transaction $transaction): string
    {
        return match ($transaction?->statut) {
            null => 'Formule gratuite activée.',
            Transaction::STATUT_REUSSIE => 'Paiement confirmé, votre abonnement est actif.',
            Transaction::STATUT_ECHOUEE => $transaction->motif_echec ?? 'Le paiement a échoué.',
            default => 'Confirmez le paiement sur votre téléphone pour activer votre abonnement.',
        };
    }
}
