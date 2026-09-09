<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Abonnement;
use App\Models\Artisan;
use App\Models\Chauffeur;
use App\Models\Commission;
use App\Models\Course;
use App\Models\DemandeDevis;
use App\Models\Litige;
use App\Models\Report;
use App\Models\Transaction;
use App\Models\User;
use App\Models\VerificationIdentite;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Tableau de bord de l'administration (§5.4.1).
 *
 * Indicateurs clés en temps réel, graphique d'activité et signalements en
 * attente de traitement.
 */
class TableauDeBordController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $jours = (int) $request->integer('jours', 7);
        $jours = in_array($jours, [7, 30], true) ? $jours : 7;

        return response()->json([
            'indicateurs' => $this->indicateurs(),
            'activite' => $this->activite($jours),
            'aTraiter' => $this->fileDAttente(),
        ]);
    }

    /**
     * Missions actives, revenus, utilisateurs et artisans actifs (§4.4).
     */
    private function indicateurs(): array
    {
        $revenus = Transaction::where('statut', Transaction::STATUT_REUSSIE);

        return [
            'missionsActives' => DemandeDevis::whereIn('statut', [
                DemandeDevis::STATUT_EN_ATTENTE,
                DemandeDevis::STATUT_ACCEPTEE,
                DemandeDevis::STATUT_EN_COURS,
            ])->count(),
            'coursesActives' => Course::actives()->count(),

            'utilisateurs' => User::count(),
            'nouveauxUtilisateurs7j' => User::where('created_at', '>=', now()->subDays(7))->count(),

            // « Actif » ne veut pas dire « inscrit » : un artisan validé qui
            // n'a rien fait depuis trois mois ne compte pas.
            'artisansActifs' => Artisan::where('statut_validation', Artisan::VALIDATION_VALIDE)
                ->whereHas('utilisateur', fn ($r) => $r->where('derniere_connexion_at', '>=', now()->subDays(30)))
                ->count(),
            'artisansTotal' => Artisan::count(),
            'chauffeursEnLigne' => Chauffeur::where('en_ligne', true)->count(),
            'chauffeursTotal' => Chauffeur::count(),

            'revenusMois' => (float) (clone $revenus)
                ->where('payee_at', '>=', now()->startOfMonth())
                ->sum('montant'),
            'revenusTotal' => (float) (clone $revenus)->sum('montant'),
            'commissionsMois' => (float) Commission::whereHas(
                'transaction',
                fn ($r) => $r->where('payee_at', '>=', now()->startOfMonth()),
            )->sum('montant'),

            'abonnementsActifs' => Abonnement::where('statut', Abonnement::STATUT_ACTIF)->count(),
        ];
    }

    /**
     * Séries d'activité sur 7 ou 30 jours.
     *
     * Mises en cache cinq minutes : le tableau de bord se rafraîchit souvent,
     * et ces agrégats ne bougent pas à la seconde.
     */
    private function activite(int $jours): array
    {
        return Cache::remember("admin.activite.{$jours}", now()->addMinutes(5), function () use ($jours) {
            $depuis = now()->subDays($jours - 1)->startOfDay();

            $parJour = fn (string $table, string $colonne) => DB::table($table)
                ->where($colonne, '>=', $depuis)
                ->selectRaw("date({$colonne}) as jour, count(*) as total")
                ->groupBy('jour')
                ->pluck('total', 'jour');

            $inscriptions = $parJour('users', 'created_at');
            $demandes = $parJour('demandes_devis', 'created_at');
            $courses = $parJour('courses', 'created_at');

            return collect(range($jours - 1, 0))->map(function (int $recul) use ($inscriptions, $demandes, $courses) {
                $jour = now()->subDays($recul)->toDateString();

                return [
                    'jour' => $jour,
                    'inscriptions' => (int) ($inscriptions[$jour] ?? 0),
                    'demandes' => (int) ($demandes[$jour] ?? 0),
                    'courses' => (int) ($courses[$jour] ?? 0),
                ];
            })->values()->all();
        });
    }

    /** Ce qui attend une décision humaine. */
    private function fileDAttente(): array
    {
        return [
            'signalements' => Report::where('statut', 'en_attente')->count(),
            'artisansAValider' => Artisan::where('statut_validation', Artisan::VALIDATION_EN_ATTENTE)->count(),
            'chauffeursAValider' => Chauffeur::where('statut_validation', Artisan::VALIDATION_EN_ATTENTE)->count(),
            'identitesAVerifier' => VerificationIdentite::where('statut', 'en_attente')->count(),
            'litigesOuverts' => Litige::whereIn('statut', ['ouvert', 'en_cours'])->count(),
        ];
    }
}
