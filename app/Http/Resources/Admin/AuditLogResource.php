<?php

namespace App\Http\Resources\Admin;

use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Log d'audit (§5.4).
 *
 * @property-read AuditLog $resource
 */
class AuditLogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $auteur = $this->resource->auteur;

        return [
            'id' => $this->resource->id,
            'action' => $this->resource->action,
            'auteur' => $auteur ? trim(($auteur->prenom ?? '').' '.$auteur->nom) : 'Système',
            'auteurEmail' => $auteur?->email,
            'cibleType' => $this->resource->cible_type,
            'cibleId' => $this->resource->cible_id,
            'avant' => $this->resource->avant,
            'apres' => $this->resource->apres,
            'adresseIp' => $this->resource->adresse_ip,
            'createdAt' => $this->resource->created_at?->toIso8601String(),
        ];
    }
}
