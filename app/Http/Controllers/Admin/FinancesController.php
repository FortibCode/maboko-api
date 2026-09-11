<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Abonnement;
use App\Models\Artisan;
use App\Models\Commission;
use App\Models\Plan;
use App\Models\Transaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Abonnements et commissions (§5.4.4).
 *
 * Synthèse financière du mois, répartition des artisans par formule, et
 * export du rapport.
 */
class FinancesController extends Controller
{
    public function synthese(Request $request): JsonResponse
    {
        [$debut, $fin] = $this->periode($request);

        $transactions = Transaction::where('statut', Transaction::STATUT_REUSSIE)
            ->whereBetween('payee_at', [$debut, $fin]);

        return response()->json([
            'periode' => ['debut' => $debut->toDateString(), 'fin' => $fin->toDateString()],

            'revenus' => [
                'total' => (float) (clone $transactions)->sum('montant'),
                'nbTransactions' => (clone $transactions)->count(),
                // toBase() : une agrégation ne produit pas des modèles mais
                // des lignes brutes ; les hydrater n'aurait aucun sens.
                'parOperateur' => (clone $transactions)
                    ->toBase()
                    ->selectRaw('operateur, count(*) as nombre, coalesce(sum(montant), 0) as total')
                    ->groupBy('operateur')
                    ->get()
                    ->map(fn (object $ligne) => [
                        'operateur' => $ligne->operateur,
                        'nombre' => (int) $ligne->nombre,
                        'total' => (float) $ligne->total,
                    ]),
            ],

            'commissions' => [
                'total' => (float) Commission::whereHas(
                    'transaction',
                    fn ($r) => $r->where('statut', Transaction::STATUT_REUSSIE)->whereBetween('payee_at', [$debut, $fin]),
                )->sum('montant'),
                'parType' => Commission::whereHas(
                    'transaction',
                    fn ($r) => $r->where('statut', Transaction::STATUT_REUSSIE)->whereBetween('payee_at', [$debut, $fin]),
                )
                    ->selectRaw('type, coalesce(sum(montant), 0) as total')
                    ->groupBy('type')
                    ->pluck('total', 'type')
                    ->map(fn ($t) => (float) $t),
            ],

            // Répartition des artisans par formule d'abonnement (§5.4.4).
            'repartitionAbonnements' => $this->repartition(),

            'echecs' => [
                'nombre' => Transaction::where('statut', Transaction::STATUT_ECHOUEE)
                    ->whereBetween('created_at', [$debut, $fin])
                    ->count(),
                'montant' => (float) Transaction::where('statut', Transaction::STATUT_ECHOUEE)
                    ->whereBetween('created_at', [$debut, $fin])
                    ->sum('montant'),
            ],
        ]);
    }

    /**
     * Export du rapport financier (§5.4.4).
     *
     * Format CSV avec séparateur point-virgule : c'est ce qu'attend un tableur
     * configuré en français, où la virgule sert de séparateur décimal.
     */
    public function exporter(Request $request): StreamedResponse
    {
        [$debut, $fin] = $this->periode($request);

        $transactions = Transaction::with(['user:id,nom,prenom,email,role', 'commission'])
            ->where('statut', Transaction::STATUT_REUSSIE)
            ->whereBetween('payee_at', [$debut, $fin])
            ->orderBy('payee_at');

        $nom = 'maboko-finances-'.$debut->format('Y-m-d').'-'.$fin->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($transactions) {
            $sortie = fopen('php://output', 'w');

            // BOM UTF-8 : sans lui, Excel affiche « FrancÌ§ois » au lieu de « François ».
            fwrite($sortie, "\xEF\xBB\xBF");

            fputcsv($sortie, [
                'Référence', 'Date de paiement', 'Client', 'E-mail',
                'Opérateur', 'Montant (FCFA)', 'Commission (FCFA)', 'Type',
            ], ';');

            // Par lots : un export annuel ne doit pas charger toute la table
            // en mémoire.
            $transactions->chunk(500, function ($lot) use ($sortie) {
                foreach ($lot as $transaction) {
                    fputcsv($sortie, [
                        $transaction->reference_interne,
                        $transaction->payee_at?->format('d/m/Y H:i'),
                        trim(($transaction->user->prenom ?? '').' '.($transaction->user->nom ?? '')),
                        $transaction->user->email ?? '',
                        $transaction->operateur,
                        number_format((float) $transaction->montant, 0, ',', ''),
                        number_format((float) ($transaction->commission->montant ?? 0), 0, ',', ''),
                        $transaction->commission->type ?? '',
                    ], ';');
                }
            });

            fclose($sortie);
        }, $nom, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function repartition(): array
    {
        $actifs = Abonnement::where('statut', Abonnement::STATUT_ACTIF)
            ->selectRaw('plan_id, count(*) as nombre')
            ->groupBy('plan_id')
            ->pluck('nombre', 'plan_id');

        $totalArtisans = Artisan::count();
        $abonnes = $actifs->sum();

        $repartition = Plan::orderBy('ordre')->get()->map(function (Plan $plan) use ($actifs, $totalArtisans) {
            $nombre = (int) ($actifs[$plan->id] ?? 0);

            return [
                'plan' => $plan->nom,
                'slug' => $plan->slug,
                'nombre' => $nombre,
                'revenuMensuelTheorique' => $nombre * (float) $plan->prix_mensuel,
                'part' => $totalArtisans > 0 ? round($nombre / $totalArtisans * 100, 1) : 0.0,
            ];
        })->all();

        // Les artisans sans abonnement actif sont de fait au plan gratuit :
        // les omettre fausserait la lecture des parts.
        $sansAbonnement = max(0, $totalArtisans - $abonnes);

        if ($sansAbonnement > 0 && isset($repartition[0])) {
            $repartition[0]['nombre'] += $sansAbonnement;
            $repartition[0]['part'] = $totalArtisans > 0
                ? round($repartition[0]['nombre'] / $totalArtisans * 100, 1)
                : 0.0;
        }

        return $repartition;
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    private function periode(Request $request): array
    {
        $debut = $request->filled('debut')
            ? Carbon::parse($request->string('debut'))->startOfDay()
            : now()->startOfMonth();

        $fin = $request->filled('fin')
            ? Carbon::parse($request->string('fin'))->endOfDay()
            : now()->endOfMonth();

        return [$debut, $fin];
    }
}
