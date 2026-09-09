<?php

namespace App\Services;

use App\Models\Tarif;

/**
 * Estimation du prix d'une course (§4.1, §5.1.8).
 *
 * Le client voit le tarif avant de réserver : c'est ce qui remplace la
 * négociation au bord de la route, et la condition pour qu'il accepte de
 * commander sans connaître le chauffeur.
 */
class TarificationCourse
{
    /** Vitesse moyenne retenue en ville, en km/h. */
    private const VITESSE_MOTO = 25;

    private const VITESSE_VOITURE = 20;

    /**
     * Distance à vol d'oiseau entre deux points, en kilomètres.
     * Formule de haversine.
     */
    public function distanceKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $rayonTerre = 6371.0;

        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);

        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return round($rayonTerre * 2 * atan2(sqrt($a), sqrt(1 - $a)), 2);
    }

    /**
     * Estime distance, durée et tarif d'un trajet.
     *
     * @return array{distanceKm: float, dureeMin: int, tarif: float, tarifDetail: array<string, float>}
     */
    public function estimer(
        float $departLat,
        float $departLng,
        float $arriveeLat,
        float $arriveeLng,
        string $typeVehicule,
    ): array {
        $tarif = Tarif::where('type_vehicule', $typeVehicule)->where('actif', true)->firstOrFail();

        $volDOiseau = $this->distanceKm($departLat, $departLng, $arriveeLat, $arriveeLng);

        // Le trajet réel dépasse toujours la ligne droite : les rues de
        // Brazzaville ne sont pas un damier. Le facteur 1,35 est une
        // approximation à caler sur les courses réelles après le lancement.
        $distance = round($volDOiseau * 1.35, 2);

        $vitesse = $typeVehicule === 'moto' ? self::VITESSE_MOTO : self::VITESSE_VOITURE;
        $duree = (int) max(5, ceil($distance / $vitesse * 60));

        return [
            'distanceKm' => $distance,
            'dureeMin' => $duree,
            'tarif' => $tarif->estimer($distance, $duree),
            'tarifDetail' => [
                'base' => (float) $tarif->prix_base,
                'parKm' => (float) $tarif->prix_km,
                'parMinute' => (float) $tarif->prix_minute,
                'minimum' => (float) $tarif->course_minimum,
            ],
        ];
    }
}
