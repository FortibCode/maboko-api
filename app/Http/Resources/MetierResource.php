<?php

namespace App\Http\Resources;

use App\Models\Metier;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read Metier $resource
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
            'description' => $this->resource->description,
            'nbArtisans' => $this->whenCounted('artisans'),
            'niveau' => $this->whenPivotLoaded('artisan_metier', fn () => $this->resource->pivot->niveau),
            'principal' => $this->whenPivotLoaded('artisan_metier', fn () => (bool) $this->resource->pivot->principal),
        ];
    }
}
