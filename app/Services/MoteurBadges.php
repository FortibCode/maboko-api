<?php

namespace App\Services;

use App\Models\Artisan;
use App\Models\Badge;
use Illuminate\Support\Collection;

/**
 * Progression automatique dans les badges (§4.5).
 *
 * « Chaque artisan progresse automatiquement dans les badges selon son nombre
 * de missions réalisées, sa note moyenne et son ancienneté sur la plateforme. »
 *
 * Les conditions ne sont pas écrites en dur : elles vivent dans la colonne
 * « regle_attribution » du référentiel, ce qui permet de les ajuster sans
 * toucher au code.
 */
class MoteurBadges
{
    public function __construct(private ClassementArtisan $classement) {}

    /**
     * Réévalue tous les badges automatiques d'un artisan.
     *
     * @return Collection<int, Badge> les badges nouvellement obtenus
     */
    public function reevaluer(Artisan $artisan): Collection
    {
        $artisan->loadMissing('badges');

        $dejaObtenus = $artisan->badges->pluck('id')->all();
        $nouveaux = collect();

        $candidats = Badge::where('automatique', true)
            ->whereNotIn('id', $dejaObtenus)
            ->get();

        foreach ($candidats as $badge) {
            if ($this->conditionsRemplies($artisan, $badge)) {
                $artisan->badges()->attach($badge->id, ['obtenu_at' => now()]);
                $nouveaux->push($badge);
            }
        }

        // « Nouveau » ne vaut que pour un profil récemment inscrit : il doit
        // disparaître quand la condition d'ancienneté n'est plus remplie.
        $perimes = $artisan->badges
            ->filter(fn (Badge $badge) => $badge->automatique
                && isset($badge->regle_attribution['anciennete_jours_max'])
                && ! $this->conditionsRemplies($artisan, $badge));

        if ($perimes->isNotEmpty()) {
            $artisan->badges()->detach($perimes->pluck('id')->all());
        }

        if ($nouveaux->isNotEmpty() || $perimes->isNotEmpty()) {
            // Un badge pèse dans le score de recherche : la position doit
            // suivre immédiatement, sinon l'artisan ne remonte qu'au prochain avis.
            $this->classement->recalculerScore($artisan->fresh(['badges', 'abonnementActif.plan']));
        }

        return $nouveaux;
    }

    /**
     * Attribution manuelle par l'administration : « Certifié Maboko »,
     * « Profil vérifié » et « Atelier reconnu » ne s'obtiennent pas tout seuls.
     */
    public function attribuerManuellement(Artisan $artisan, Badge $badge, ?int $administrateurId = null): bool
    {
        if ($artisan->badges()->where('badges.id', $badge->id)->exists()) {
            return false;
        }

        $artisan->badges()->attach($badge->id, [
            'obtenu_at' => now(),
            'attribue_par' => $administrateurId,
        ]);

        $this->classement->recalculerScore($artisan->fresh(['badges', 'abonnementActif.plan']));

        return true;
    }

    public function retirer(Artisan $artisan, Badge $badge): void
    {
        $artisan->badges()->detach($badge->id);

        $this->classement->recalculerScore($artisan->fresh(['badges', 'abonnementActif.plan']));
    }

    /**
     * Toutes les conditions de la règle doivent être remplies.
     * Une règle vide ne s'accorde jamais toute seule.
     */
    private function conditionsRemplies(Artisan $artisan, Badge $badge): bool
    {
        $regle = $badge->regle_attribution;

        // Une règle vide ne s'accorde jamais toute seule.
        if (empty($regle)) {
            return false;
        }

        $anciennete = $artisan->created_at?->diffInDays(now()) ?? 0;

        foreach ($regle as $critere => $attendu) {
            $rempli = match ($critere) {
                'missions_terminees_min' => $artisan->nb_missions_terminees >= $attendu,
                'note_moyenne_min' => (float) $artisan->note_moyenne >= (float) $attendu,
                'nb_avis_min' => $artisan->nb_avis >= $attendu,
                'anciennete_jours_min' => $anciennete >= $attendu,
                'anciennete_jours_max' => $anciennete <= $attendu,
                'local_professionnel' => (bool) $artisan->local_professionnel === (bool) $attendu,
                // Un critère inconnu ne doit jamais accorder un badge par défaut.
                default => false,
            };

            if (! $rempli) {
                return false;
            }
        }

        return true;
    }
}
