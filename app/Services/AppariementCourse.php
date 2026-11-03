<?php

namespace App\Services;

use App\Models\Chauffeur;
use App\Models\Course;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class AppariementCourse
{
    /** Délai au-delà duquel une course n'est plus proposée (15 minutes). */
    public const EXPIRATION_MINUTES = 15;
    
    /** Rayon maximum pour proposer une course à un chauffeur (en km). */
    public const RAYON_RECHERCHE_KM = 15;

    /**
     * Trouve les chauffeurs les plus pertinents pour une course donnée.
     */
    public function candidats(Course $course): Collection
    {
        // ⏱️ On définit la limite de fraicheur du GPS (5 minutes)
        $positionRecenteMin = now()->subMinutes(5);

        // 1. On filtre les chauffeurs en base de données
        $chauffeurs = Chauffeur::query()
            ->where('en_ligne', true)
            ->where('disponibilite', true)
            ->where('statut_validation', Chauffeur::VALIDATION_VALIDE)
            ->where('type_vehicule', $course->type_vehicule)
            ->whereHas('dernierePosition', function ($query) use ($positionRecenteMin) {
                // 👇 On ignore ceux qui n'ont pas envoyé de GPS depuis 5 min
                $query->where('releve_at', '>=', $positionRecenteMin);
            })
            ->with('dernierePosition')
            ->get();

        // 2. On calcule la distance en PHP pour ne garder que ceux dans le rayon
        return $chauffeurs->filter(function (Chauffeur $chauffeur) use ($course) {
            $position = $chauffeur->dernierePosition;
            if (!$position) return false;

            $distance = $this->calculerDistance(
                (float) $position->latitude,
                (float) $position->longitude,
                (float) $course->depart_latitude,
                (float) $course->depart_longitude
            );

            // On garde le chauffeur seulement s'il est dans le rayon
            return $distance <= self::RAYON_RECHERCHE_KM;
        });
    }

    /**
     * Attribue une course à un chauffeur de manière atomique.
     * Empêche deux chauffeurs d'accepter la même course en même temps.
     */
    public function attribuer(Course $course, Chauffeur $chauffeur): bool
    {
        // On utilise une transaction pour verrouiller la ligne de la course
        return DB::transaction(function () use ($course, $chauffeur) {
            // On vérifie que la course est toujours disponible
            $courseVerrouillee = Course::where('id', $course->id)
                ->where('statut', Course::STATUT_RECHERCHE)
                ->lockForUpdate() // 🔒 Empêche les conflits
                ->first();

            if (! $courseVerrouillee) {
                return false; // Un autre chauffeur a été plus rapide
            }

            // On attribue la course
            $courseVerrouillee->update([
                'chauffeur_id' => $chauffeur->id,
                'statut' => Course::STATUT_ACCEPTEE,
            ]);

            // On rend le chauffeur indisponible
            $chauffeur->update(['disponibilite' => false]);

            return true;
        });
    }

    /**
     * Libère le chauffeur après la fin ou l'annulation de la course.
     */
    public function libererChauffeur(Course $course): void
    {
        if ($course->chauffeur) {
            $course->chauffeur->update(['disponibilite' => true]);
        }
    }

    /**
     * Formule de Haversine pour calculer la distance entre deux points GPS.
     */
    private function calculerDistance(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $rayonTerre = 6371.0; // en km

        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);

        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;

        return round($rayonTerre * 2 * atan2(sqrt($a), sqrt(1 - $a)), 1);
    }
}