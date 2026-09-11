<?php

namespace App\Http\Resources\Admin;

use App\Models\Litige;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Litige entre utilisateurs (§3.4).
 *
 * @property-read Litige $resource
 */
class LitigeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $auteur = $this->resource->auteur;
        $responsable = $this->resource->responsable;

        return [
            'id' => $this->resource->id,
            'motif' => $this->resource->motif,
            'description' => $this->resource->description,
            'statut' => $this->resource->statut,
            'auteur' => $auteur === null ? null : trim(($auteur->prenom ?? '').' '.$auteur->nom),
            'assigneA' => $responsable === null ? null : trim(($responsable->prenom ?? '').' '.$responsable->nom),
            'resolution' => $this->resource->resolution,
            'ouvertLe' => $this->resource->created_at?->toIso8601String(),
        ];
    }
}
