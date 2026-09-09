<?php

namespace App\Console\Commands;

use App\Models\Abonnement;
use App\Models\Notification;
use App\Models\Transaction;
use App\Services\AbonnementService;
use App\Services\Paiement\RegistrePasserelles;
use Illuminate\Console\Command;

/**
 * Facturation récurrente des abonnements (§4.5).
 *
 * Trois traitements enchaînés, exécutés une fois par jour :
 *   - relancer les abonnements qui arrivent à échéance ;
 *   - prélever ceux dont le renouvellement automatique est actif ;
 *   - clore ceux qui sont expirés sans paiement.
 */
class RenouvelerAbonnements extends Command
{
    protected $signature = 'maboko:renouveler-abonnements {--simulation : n\'écrit rien, affiche seulement}';

    protected $description = 'Relance, renouvelle et clôt les abonnements arrivés à échéance';

    public function handle(AbonnementService $abonnements, RegistrePasserelles $passerelles): int
    {
        $simulation = (bool) $this->option('simulation');

        $this->info($simulation ? 'Mode simulation : aucune écriture.' : 'Traitement des abonnements…');

        $relances = $this->relancer($simulation);
        $renouveles = $this->renouveler($abonnements, $simulation);
        $expires = $this->cloturer($simulation);

        $this->table(
            ['Relances envoyées', 'Renouvellements', 'Abonnements clos'],
            [[$relances, $renouveles, $expires]],
        );

        return self::SUCCESS;
    }

    /** Prévient trois jours avant l'échéance. */
    private function relancer(bool $simulation): int
    {
        $echeance = now()->addDays(3)->toDateString();

        $abonnements = Abonnement::with(['artisan.utilisateur', 'plan'])
            ->where('statut', Abonnement::STATUT_ACTIF)
            ->whereDate('fin', $echeance)
            ->where(function ($requete) {
                $requete->whereNull('derniere_relance_at')
                    ->orWhere('derniere_relance_at', '<', now()->subDays(2));
            })
            ->get();

        foreach ($abonnements as $abonnement) {
            if ($simulation) {
                continue;
            }

            Notification::create([
                'artisan_id' => $abonnement->artisan->utilisateur_id,
                'title' => 'Votre abonnement arrive à échéance',
                'body' => "Votre formule {$abonnement->plan->nom} expire le "
                    .$abonnement->fin->format('d/m/Y').'.',
                'type' => 'abonnement_echeance',
                'canal' => 'push',
            ]);

            $abonnement->update(['derniere_relance_at' => now()]);
        }

        return $abonnements->count();
    }

    /** Prélève les abonnements échus dont le renouvellement est automatique. */
    private function renouveler(AbonnementService $service, bool $simulation): int
    {
        $abonnements = Abonnement::with(['artisan.utilisateur', 'plan'])
            ->where('statut', Abonnement::STATUT_ACTIF)
            ->where('renouvellement_auto', true)
            ->whereDate('fin', '<=', now()->toDateString())
            ->get();

        $traites = 0;

        foreach ($abonnements as $abonnement) {
            $artisan = $abonnement->artisan;
            $telephone = $artisan?->utilisateur?->telephone;

            if (! $artisan || ! $telephone) {
                continue;
            }

            $this->line("  → {$abonnement->plan->nom} pour l'artisan #{$artisan->id}");

            if ($simulation) {
                $traites++;

                continue;
            }

            // Le dernier opérateur utilisé est repris ; à défaut, MTN.
            $operateur = Transaction::where('user_id', $artisan->utilisateur_id)
                ->where('statut', Transaction::STATUT_REUSSIE)
                ->latest()
                ->value('operateur') ?? Transaction::OPERATEUR_MTN;

            $service->souscrire($artisan, $abonnement->plan, $abonnement->periodicite, $operateur, $telephone);
            $traites++;
        }

        return $traites;
    }

    /**
     * Clôt les abonnements expirés depuis plus de trois jours.
     *
     * Le délai laisse le temps à un paiement Mobile Money d'aboutir : un
     * artisan ne doit pas perdre sa visibilité parce que l'opérateur a mis
     * quelques heures à confirmer.
     */
    private function cloturer(bool $simulation): int
    {
        $requete = Abonnement::where('statut', Abonnement::STATUT_ACTIF)
            ->whereDate('fin', '<', now()->subDays(3)->toDateString());

        $nombre = $requete->count();

        if (! $simulation && $nombre > 0) {
            $requete->update(['statut' => Abonnement::STATUT_EXPIRE]);
        }

        return $nombre;
    }
}
