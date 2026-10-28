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
            'id' => $this->resource->id,
            'nom' => $this->resource->nom,
            'slug' => $this->resource->slug,
            'icone' => $this->resource->icone,
            // Photo réelle du métier quand l'administration en a déposé une ;
            // l'application retombe sur l'icône sinon.
            'imageUrl' => $this->resource->image_url,
            'description' => $this->resource->description,
            // L'écran d'administration pilote ces deux champs.
            'ordre' => (int) $this->resource->ordre,
            'actif' => (bool) $this->resource->actif,
            'nbArtisans' => $this->whenCounted('artisans'),
            'niveau' => $this->whenPivotLoaded('artisan_metier', fn () => $this->resource->pivot->niveau),
            'principal' => $this->whenPivotLoaded('artisan_metier', fn () => (bool) $this->resource->pivot->principal),
        ];
    }
}