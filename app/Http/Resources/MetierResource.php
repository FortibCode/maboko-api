<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Format d'un métier renvoyé par l'API.
 */
class MetierResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'nom' => $this->nom,
            'slug' => $this->slug,
            'icone' => $this->icone,

            // URL de la photo déposée par l'administration. Nulle tant
            // qu'il n'y en a pas : le mobile retombe alors sur l'icône.
            'imageUrl' => $this->image_url,

            'description' => $this->description,
            'nbArtisans' => $this->artisans_count ?? 0,
        ];
    }
}