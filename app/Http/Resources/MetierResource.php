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
            // Photo réelle du métier quand l'administration en a déposé une ;
            // l'application retombe sur l'icône sinon.
            'imageUrl' => $this->resource->image_url,
            'description' => $this->resource->description,
            // L'ecran d'administration pilote ces deux champs : sans eux, son
            // formulaire repartait d'un ordre vide et remettait le metier a 0
            // a chaque enregistrement.
            'ordre' => (int) $this->resource->ordre,
            'actif' => (bool) $this->resource->actif,
            'nbArtisans' => $this->whenCounted('artisans'),
            'niveau' => $this->whenPivotLoaded('artisan_metier', fn () => $this->resource->pivot->niveau),
            'principal' => $this->whenPivotLoaded('artisan_metier', fn () => (bool) $this->resource->pivot->principal),
        ];
    }
}
