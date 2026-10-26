<?php

namespace App\Services;

use App\Models\Chauffeur;
use App\Models\Course;
use Illuminate\Support\Collection;

/**
 * Appariement d'une course avec un chauffeur (§4.3).
 *
 * La course est proposée aux chauffeurs disponibles les plus proches, du plus
 * près au plus loin. Le premier qui accepte l'emporte : l'attribution se joue
 * sur une mise à jour conditionnelle, pour que deux acceptations simultanées
 * ne donnent pas la même course à deux chauffeurs.
 */
class AppariementCourse
{
    /** Rayon de recherche initial, en kilomètres. */
    public const RAYON_KM = 5;

    /** Rayon élargi si personne n'est trouvé à proximité. */
    public const RAYON_ELARGI_KM = 12;

    /** Au-delà, une course sans preneur est abandonnée. */
    public const EXPIRATION_MINUTES = 10;

    /**
     * Chauffeurs joignables pour cette course, triés par distance.
     *
     * @return Collection<int, Chauffeur>
     */
    public function candidats(Course $course, int $limite = 10): Collection
    {
        $trouves = $this->chercher($course, self::RAYON_KM, $limite);

        // Personne à proximité : on élargit plutôt que de laisser le client
        // sans réponse, quitte à allonger son attente.
        if ($trouves->isEmpty()) {
            $trouves = $this->chercher($course, self::RAYON_ELARGI_KM, $limite);
        }

        return $trouves;
    }

    /**
     * Attribue la course au chauffeur, si elle est encore libre.
     *
     * Retourne false lorsqu'un autre chauffeur a été plus rapide : c'est le
     * cas normal en heure de pointe, pas une erreur.
     */
    public function attribuer(Course $course, Chauffeur $chauffeur): bool
    {
        $attribuee = Course::whereKey($course->id)
            ->where('statut', Course::STATUT_RECHERCHE)
            ->whereNull('chauffeur_id')
            ->update([
                'chauffeur_id' => $chauffeur->id,
                'statut' => Course::STATUT_ACCEPTEE,
                'acceptee_at' => now(),
            ]);

        if ($attribuee === 0) {
            return false;
        }

        // Le chauffeur n'est plus proposable tant qu'il n'a pas déposé.
        $chauffeur->update(['disponibilite' => false]);

        return true;
    }

    /** Rend le chauffeur disponible en fin de course ou après annulation. */
    public function libererChauffeur(Course $course): void
    {
        $course->chauffeur?->update(['disponibilite' => true]);
    }

    /**
     * @return Collection<int, Chauffeur>
     */
    private function chercher(Course $course, int $rayonKm, int $limite): Collection
    {
        $lat = (float) $course->depart_latitude;
        $lng = (float) $course->depart_longitude;

        // Haversine exprimée en SQL, portable PostgreSQL / MySQL / SQLite.
        // Un index géographique Redis serait plus rapide à grande échelle ;
        // à quelques centaines de chauffeurs, cette requête suffit.
        $distance = '(6371 * acos(cos(radians(?)) * cos(radians(p.latitude))'
            .' * cos(radians(p.longitude) - radians(?))'
            .' + sin(radians(?)) * sin(radians(p.latitude))))';

        return Chauffeur::query()
            // L'appelant notifie chaque candidat : sans ce chargement, dix
            // chauffeurs proches produiraient dix requêtes supplémentaires.
            ->with('utilisateur:id,nom,prenom,telephone,role')
            ->disponibles()
            ->where('type_vehicule', $course->type_vehicule)
            ->join('positions_chauffeurs as p', function ($jointure) {
                // Seule la dernière position de chaque chauffeur compte.
                $jointure->on('p.chauffeur_id', '=', 'chauffeurs.id')
                    ->whereRaw('p.id = (select max(id) from positions_chauffeurs where chauffeur_id = chauffeurs.id)');
            })
            // Une position trop ancienne ne prouve plus rien : le chauffeur
            // a pu éteindre son téléphone sans se déclarer hors ligne.
            ->where('p.releve_at', '>=', now()->subMinutes(5))
            ->selectRaw("chauffeurs.*, {$distance} as distance_km", [$lat, $lng, $lat])
            ->whereRaw("{$distance} <= ?", [$lat, $lng, $lat, $rayonKm])
            ->orderBy('distance_km')
            ->limit($limite)
            ->get();
    }
}
