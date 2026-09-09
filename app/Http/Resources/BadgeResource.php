<?php

namespace App\Http\Resources;

use App\Models\Badge;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read Badge $resource
 */
class BadgeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'slug' => $this->resource->slug,
            'nom' => $this->resource->nom,
            'description' => $this->resource->description,
            'icone' => $this->resource->icone,
            'obtenuLe' => $this->whenPivotLoaded('artisan_badge', fn () => $this->resource->pivot->obtenu_at),
        ];
    }
}
