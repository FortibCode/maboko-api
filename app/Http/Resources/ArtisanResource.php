<?php

namespace App\Http\Resources;

use App\Models\Artisan;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Fiche artisan telle que la consulte un client (§5.1.6).
 *
 * @property-read Artisan $resource
 */
class ArtisanResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'utilisateur' => new UtilisateurResource($this->whenLoaded('utilisateur')),
            'specialite' => $this->resource->specialite,
            'bio' => $this->resource->bio,
            'adresse' => $this->resource->adresse,
            'zoneIntervention' => $this->resource->zone_intervention,
            'rayonKm' => $this->resource->rayon_km,

            'noteMoyenne' => (float) $this->resource->note_moyenne,
            'nbAvis' => $this->resource->nb_avis,
            'nbMissionsTerminees' => $this->resource->nb_missions_terminees,

            'metiers' => MetierResource::collection($this->whenLoaded('metiers')),
            'badges' => BadgeResource::collection($this->whenLoaded('badges')),
            'avis' => AvisResource::collection($this->whenLoaded('avis')),

            'localProfessionnel' => $this->resource->local_professionnel,
            'plan' => $this->whenLoaded('abonnementActif', fn () => $this->resource->abonnementActif?->plan?->slug),

            // Presente uniquement sur une recherche geolocalisee.
            'distanceKm' => $this->when(
                isset($this->resource->distance_km),
                fn () => round((float) $this->resource->distance_km, 1),
            ),
        ];
    }
}
