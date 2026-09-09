<?php

namespace App\Http\Resources;

use App\Models\Course;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Course « Allô Chauffeur » (§5.1.8, §5.3).
 *
 * @property-read Course $resource
 */
class CourseResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $chauffeur = $this->resource->chauffeur;

        return [
            'id' => $this->resource->id,
            'statut' => $this->resource->statut,
            'typeVehicule' => $this->resource->type_vehicule,

            'depart' => [
                'adresse' => $this->resource->lieu_depart,
                'latitude' => (float) $this->resource->depart_latitude,
                'longitude' => (float) $this->resource->depart_longitude,
            ],
            'arrivee' => [
                'adresse' => $this->resource->lieu_arrivee,
                'latitude' => (float) $this->resource->arrivee_latitude,
                'longitude' => (float) $this->resource->arrivee_longitude,
            ],

            'distanceKm' => (float) $this->resource->distance_km,
            'dureeEstimeeMin' => $this->resource->duree_estimee_min,
            'tarifEstime' => (float) $this->resource->tarif_estime,
            'tarifFinal' => $this->resource->tarif_final ? (float) $this->resource->tarif_final : null,

            'client' => new UtilisateurResource($this->whenLoaded('client')),

            'chauffeur' => $chauffeur === null ? null : [
                'id' => $chauffeur->id,
                'nomComplet' => trim(($chauffeur->utilisateur->prenom ?? '').' '.$chauffeur->utilisateur->nom),
                'avatarUrl' => $chauffeur->utilisateur->avatar_url,
                // Le client doit pouvoir reconnaître le véhicule qui arrive.
                'vehicule' => $chauffeur->vehicule_modele,
                'plaque' => $chauffeur->plaque_immatriculation,
                'telephone' => $chauffeur->utilisateur->telephone,
                'noteMoyenne' => (float) $chauffeur->note_moyenne,
                'position' => $this->positionChauffeur(),
            ],

            'annuleePar' => $this->resource->annulee_par,
            'motifAnnulation' => $this->resource->motif_annulation,

            'accepteeLe' => $this->resource->acceptee_at?->toIso8601String(),
            'priseEnChargeLe' => $this->resource->prise_en_charge_at?->toIso8601String(),
            'termineeLe' => $this->resource->terminee_at?->toIso8601String(),
            'creeeLe' => $this->resource->created_at?->toIso8601String(),
        ];
    }

    /**
     * Dernière position connue du chauffeur, pour le suivi sur la carte.
     * Exposée uniquement tant que la course est en cours : après la dépose,
     * la position du chauffeur ne regarde plus le client.
     */
    private function positionChauffeur(): ?array
    {
        if (! $this->resource->estActive()) {
            return null;
        }

        $position = $this->resource->chauffeur?->dernierePosition;

        return $position === null ? null : [
            'latitude' => (float) $position->latitude,
            'longitude' => (float) $position->longitude,
            'releveeLe' => $position->releve_at?->toIso8601String(),
        ];
    }
}
