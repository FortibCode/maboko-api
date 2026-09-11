<?php

namespace App\Http\Resources\Admin;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Utilisateur global (§5.4.3).
 *
 * @property-read User $resource
 */
class UserAdminResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'nom' => $this->resource->nom,
            'prenom' => $this->resource->prenom,
            'nomComplet' => trim(($this->resource->prenom ?? '').' '.$this->resource->nom),
            'email' => $this->resource->email,
            'telephone' => $this->resource->telephone,
            'role' => $this->resource->role,
            'statut' => $this->resource->statut,
            'compteSuspendu' => $this->resource->statut === User::STATUT_SUSPENDU,
            'isVerified' => (bool) $this->resource->is_verified,
            'avatarUrl' => $this->resource->avatar_url,
            'ville' => $this->resource->ville,
            'quartier' => $this->resource->quartier,
            'derniereConnexion' => $this->resource->derniere_connexion_at?->toIso8601String(),
            'createdAt' => $this->resource->created_at?->toIso8601String(),
        ];
    }
}
