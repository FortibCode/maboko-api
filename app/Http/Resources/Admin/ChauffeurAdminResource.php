<?php

namespace App\Http\Resources\Admin;

use App\Models\Chauffeur;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read Chauffeur $resource
 */
class ChauffeurAdminResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $utilisateur = $this->resource->utilisateur;

        return [
            'id' => $this->resource->id,
            'utilisateurId' => $utilisateur->id,
            'nomComplet' => trim(($utilisateur->prenom ?? '').' '.$utilisateur->nom),
            'email' => $utilisateur->email,
            'telephone' => $utilisateur->telephone,
            'typeVehicule' => $this->resource->type_vehicule,
            'vehicule' => $this->resource->vehicule_modele,
            'plaque' => $this->resource->plaque_immatriculation,
            'permis' => $this->resource->permis_conduire,
            'statutValidation' => $this->resource->statut_validation,
            'compteSuspendu' => $utilisateur->statut === User::STATUT_SUSPENDU,
            'enLigne' => (bool) $this->resource->en_ligne,
            'nbCoursesTerminees' => $this->resource->nb_courses_terminees,
            'inscritLe' => $this->resource->created_at?->toIso8601String(),
        ];
    }
}
