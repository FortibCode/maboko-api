<?php

namespace App\Services;

use App\Models\Abonnement;
use App\Models\Artisan;
use App\Models\Commission;
use App\Models\Course;
use App\Models\DemandeDevis;
use App\Models\Plan;
use App\Models\Transaction;
use App\Services\Paiement\RegistrePasserelles;
use App\Services\Paiement\ResultatPaiement;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Souscription, paiement et renouvellement des abonnements (§4.5).
 */
class AbonnementService
{
    public function __construct(
        private RegistrePasserelles $passerelles,
        private ClassementArtisan $classement,
    ) {}

    /**
     * Souscrit un plan et lance le paiement.
     *
     * Le plan gratuit ne passe par aucun paiement : il s'active directement.
     */
    public function souscrire(
        Artisan $artisan,
        Plan $plan,
        string $periodicite,
        string $operateur,
        string $telephone,
    ): array {
        if ($plan->slug === Plan::GRATUIT) {
            return [
                'abonnement' => $this->activer($artisan, $plan, $periodicite),
                'transaction' => null,
            ];
        }

        $montant = $periodicite === 'annuel' ? (float) $plan->prix_annuel : (float) $plan->prix_mensuel;

        $transaction = Transaction::create([
            'user_id' => $artisan->utilisateur_id,
            'payable_type' => Plan::class,
            'payable_id' => $plan->id,
            'montant' => $montant,
            'devise' => config('paiement.devise'),
            'operateur' => $operateur,
            'reference_interne' => $this->genererReference(),
            'statut' => Transaction::STATUT_INITIEE,
            'payload' => ['periodicite' => $periodicite, 'artisan_id' => $artisan->id],
        ]);

        $resultat = $this->passerelles->pour($operateur)->initier($transaction, $telephone);

        $this->appliquerResultat($transaction, $resultat);

        return [
            'abonnement' => $transaction->fresh()->statut === Transaction::STATUT_REUSSIE
                ? $artisan->fresh('abonnementActif')->abonnementActif
                : null,
            'transaction' => $transaction->fresh(),
        ];
    }

    /**
     * Applique le résultat d'une passerelle à une transaction.
     *
     * Idempotent : une transaction déjà close ne rebascule pas. Les
     * opérateurs Mobile Money renvoient volontiers la même notification
     * plusieurs fois, et un abonnement ne doit pas être prolongé à chaque
     * répétition.
     */
    public function appliquerResultat(Transaction $transaction, ResultatPaiement $resultat): bool
    {
        if ($this->estClose($transaction)) {
            Log::info('[PAIEMENT] notification ignorée, transaction déjà close', [
                'reference' => $transaction->reference_interne,
                'statut' => $transaction->statut,
            ]);

            return false;
        }

        return DB::transaction(function () use ($transaction, $resultat) {
            $transaction->update([
                'statut' => $resultat->statut,
                'reference_externe' => $resultat->referenceExterne ?? $transaction->reference_externe,
                'motif_echec' => $resultat->motifEchec,
                'payload' => array_merge($transaction->payload ?? [], $resultat->payload),
                'payee_at' => $resultat->statut === Transaction::STATUT_REUSSIE ? now() : null,
            ]);

            if ($resultat->statut !== Transaction::STATUT_REUSSIE) {
                return true;
            }

            $this->enregistrerCommission($transaction);
            $this->activerDepuisTransaction($transaction);

            return true;
        });
    }

    /** Active ou prolonge l'abonnement porté par une transaction réussie. */
    private function activerDepuisTransaction(Transaction $transaction): void
    {
        if ($transaction->payable_type !== Plan::class) {
            return;
        }

        $artisan = Artisan::find($transaction->payload['artisan_id'] ?? null);
        $plan = Plan::find($transaction->payable_id);

        if (! $artisan || ! $plan) {
            Log::error('[PAIEMENT] abonnement introuvable pour la transaction', [
                'reference' => $transaction->reference_interne,
            ]);

            return;
        }

        $this->activer($artisan, $plan, $transaction->payload['periodicite'] ?? 'mensuel');
    }

    /**
     * Active le plan pour l'artisan.
     *
     * Un renouvellement prolonge la période en cours plutôt que de repartir
     * d'aujourd'hui : payer trois jours avant l'échéance ne doit pas faire
     * perdre ces trois jours.
     */
    public function activer(Artisan $artisan, Plan $plan, string $periodicite = 'mensuel'): Abonnement
    {
        /** @var Abonnement|null $courant */
        $courant = $artisan->abonnements()
            ->where('statut', Abonnement::STATUT_ACTIF)
            ->where('plan_id', $plan->id)
            ->where('fin', '>=', now()->toDateString())
            ->latest('fin')
            ->first();

        // Renouvellement : on repart de l'échéance en cours ; première
        // souscription : d'aujourd'hui.
        $depart = $courant !== null ? $courant->fin : now();
        $fin = $periodicite === 'annuel'
            ? $depart->copy()->addYear()
            : $depart->copy()->addMonth();

        if ($courant) {
            $courant->update(['fin' => $fin]);
            $abonnement = $courant;
        } else {
            // Un artisan n'a qu'une formule à la fois.
            $artisan->abonnements()
                ->where('statut', Abonnement::STATUT_ACTIF)
                ->update(['statut' => Abonnement::STATUT_ANNULE]);

            $abonnement = Abonnement::create([
                'artisan_id' => $artisan->id,
                'plan_id' => $plan->id,
                'periodicite' => $periodicite,
                'debut' => now()->toDateString(),
                'fin' => $fin->toDateString(),
                'statut' => Abonnement::STATUT_ACTIF,
                'renouvellement_auto' => true,
            ]);
        }

        // La formule pèse dans le classement de recherche (§4.5).
        $this->classement->recalculerScore($artisan->fresh(['badges', 'abonnementActif.plan']));

        return $abonnement;
    }

    /** Prélève la commission de la plateforme sur une transaction réussie. */
    private function enregistrerCommission(Transaction $transaction): void
    {
        $type = match ($transaction->payable_type) {
            Plan::class => 'abonnement',
            DemandeDevis::class => 'mission',
            Course::class => 'course',
            default => 'abonnement',
        };

        $taux = (float) (config('paiement.commission')[$type] ?? 0);

        if ($taux <= 0) {
            return;
        }

        Commission::create([
            'transaction_id' => $transaction->id,
            'type' => $type,
            'taux' => $taux,
            'montant' => round((float) $transaction->montant * $taux / 100, 2),
        ]);
    }

    private function estClose(Transaction $transaction): bool
    {
        return in_array($transaction->statut, [
            Transaction::STATUT_REUSSIE,
            Transaction::STATUT_ECHOUEE,
            Transaction::STATUT_REMBOURSEE,
        ], true);
    }

    private function genererReference(): string
    {
        return 'MBK-'.now()->format('Ymd').'-'.Str::upper(Str::random(8));
    }
}
