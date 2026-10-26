<?php

namespace App\Http\Resources\Admin;

use App\Models\Artisan;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Fiche artisan vue par l'administration (§5.4.3) : tout ce qu'il faut pour
 * décider d'une validation ou d'une suspension.
 *
 * @property-read Artisan $resource
 */
class ArtisanAdminResource extends JsonResource
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
            'specialite' => $this->resource->specialite,
            'metiers' => $this->whenLoaded('metiers', fn () => $this->resource->metiers->pluck('nom')),
            'badges' => $this->whenLoaded('badges', fn () => $this->resource->badges->pluck('nom')),
            'statutValidation' => $this->resource->statut_validation,
            'compteSuspendu' => $utilisateur->statut === User::STATUT_SUSPENDU,
            'noteMoyenne' => (float) $this->resource->note_moyenne,
            'nbAvis' => $this->resource->nb_avis,
            'nbMissionsTerminees' => $this->resource->nb_missions_terminees,
            'plan' => $this->resource->abonnementActif?->plan->nom ?? 'Gratuit',
            'derniereConnexion' => $utilisateur->derniere_connexion_at?->toIso8601String(),
            'inscritLe' => $this->resource->created_at?->toIso8601String(),
        ];
    }
}
