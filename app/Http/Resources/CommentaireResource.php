<?php

namespace App\Http\Resources;

use App\Models\Commentaire;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read Commentaire $resource
 */
class CommentaireResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'contenu' => $this->resource->contenu,
            'auteur' => new UtilisateurResource($this->whenLoaded('auteur')),
            'publieLe' => $this->resource->created_at?->toIso8601String(),
        ];
    }
}
