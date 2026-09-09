<?php

namespace App\Http\Resources;

use App\Models\Avis;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read Avis $resource
 */
class AvisResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'note' => $this->resource->note,
            'commentaire' => $this->resource->commentaire,
            'auteur' => new UtilisateurResource($this->whenLoaded('auteur')),
            'publieLe' => $this->resource->created_at?->toIso8601String(),
        ];
    }
}
