<?php

namespace App\Http\Resources;

use App\Models\Plan;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read Plan $resource
 */
class PlanResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'slug' => $this->resource->slug,
            'nom' => $this->resource->nom,
            'description' => $this->resource->description,
            'prixMensuel' => (float) $this->resource->prix_mensuel,
            'prixAnnuel' => (float) $this->resource->prix_annuel,
            'avantages' => $this->resource->avantages ?? [],
            'boostClassement' => (float) $this->resource->boost_classement,
        ];
    }
}
