<?php

namespace App\Services;

use App\Models\Artisan;
use App\Models\DemandeDevis;

/**
 * Recalcule les indicateurs de reputation d'un artisan et son score de
 * classement dans la recherche.
 *
 * Le paragraphe 4.5 prevoit que les badges et le niveau d'abonnement
 * influencent directement la position dans les resultats : le score est
 * donc la somme du poids des badges obtenus et de la reputation, multipliee
 * par le boost de la formule souscrite.
 */
class ClassementArtisan
{
    /**
     * Recalcule les indicateurs de réputation à partir des avis et des
     * missions, puis le score. À appeler après un avis ou une mission close.
     */
    public function recalculer(Artisan $artisan): void
    {
        $artisan->loadMissing(['badges', 'abonnementActif.plan']);

        $agregat = $artisan->avis()
            ->where('statut_moderation', 'publie')
            ->selectRaw('count(*) as total, coalesce(avg(note), 0) as moyenne')
            ->first();

        $nbAvis = (int) ($agregat->total ?? 0);
        $noteMoyenne = round((float) ($agregat->moyenne ?? 0), 2);

        $missionsTerminees = $artisan->demandes()
            ->where('statut', DemandeDevis::STATUT_TERMINEE)
            ->count();

        $artisan->update([
            'nb_avis' => $nbAvis,
            'note_moyenne' => $noteMoyenne,
            'nb_missions_terminees' => $missionsTerminees,
            'score_classement' => $this->score($artisan, $noteMoyenne, $nbAvis, $missionsTerminees),
        ]);
    }

    /**
     * Recalcule le seul score de classement, sans toucher aux indicateurs
     * de réputation.
     *
     * Un changement de badge ou de formule d'abonnement modifie la position
     * dans la recherche, pas le nombre d'avis reçus : les recalculer au
     * passage écraserait des valeurs qui ne sont pas en cause.
     */
    public function recalculerScore(Artisan $artisan): void
    {
        $artisan->loadMissing(['badges', 'abonnementActif.plan']);

        $artisan->update([
            'score_classement' => $this->score(
                $artisan,
                (float) $artisan->note_moyenne,
                $artisan->nb_avis,
                $artisan->nb_missions_terminees,
            ),
        ]);
    }

    private function score(Artisan $artisan, float $note, int $nbAvis, int $missions): float
    {
        // Reputation : la note ne pese qu'a proportion du nombre d'avis,
        // pour qu'un unique 5 etoiles ne devance pas un artisan tres note.
        $fiabilite = $nbAvis > 0 ? min($nbAvis / 20, 1.0) : 0.0;
        $reputation = $note * 10 * $fiabilite;

        $experience = min($missions, 100) * 0.5;

        $poidsBadges = (float) $artisan->badges->sum('poids_classement');

        // La cle etrangere plan_id est obligatoire et contrainte : si un
        // abonnement existe, son plan existe aussi.
        $boost = (float) ($artisan->abonnementActif?->plan->boost_classement ?? 1.0);

        return round(($reputation + $experience + $poidsBadges) * $boost, 2);
    }
}
