<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Representation publique d'un utilisateur.
 *
 * @property-read User $resource
 */
class UtilisateurResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'nom' => $this->resource->nom,
            'prenom' => $this->resource->prenom,
            'nomComplet' => trim($this->resource->prenom.' '.$this->resource->nom),
            'avatarUrl' => $this->resource->avatar_url,
            'ville' => $this->resource->ville,
            'quartier' => $this->resource->quartier,
            'role' => $this->resource->role,

            // Coordonnees personnelles : visibles uniquement par l'interesse
            // et par l'administration.
            $this->mergeWhen($this->peutVoirLesCoordonnees($request), fn () => [
                'email' => $this->resource->email,
                'telephone' => $this->resource->telephone,
                'statut' => $this->resource->statut,
                'estVerifie' => $this->resource->is_verified,
            ]),
        ];
    }

    private function peutVoirLesCoordonnees(Request $request): bool
    {
        $demandeur = $request->user();

        return $demandeur !== null
            && ($demandeur->id === $this->resource->id || $demandeur->estAdmin());
    }
}
