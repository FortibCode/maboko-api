<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Format d'une publicité renvoyée au mobile ou à l'admin.
 */
class PubliciteResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $artisan = $this->artisan;
        $fiche = $artisan?->artisan;

        return [
            'id' => $this->id,
            'titre' => $this->titre,
            'description' => $this->description,

            // Image de la publicité, ou à défaut avatar de l'artisan.
            'imageUrl' => $this->image_url ?? $artisan?->avatar_url,

            // Cible de la publicité : l'artisan mis en avant.
            'artisanId' => $artisan?->id,
            'nomComplet' => trim(($artisan?->prenom ?? '').' '.($artisan?->nom ?? '')),
            'avatarUrl' => $artisan?->avatar_url,
            'ville' => $artisan?->ville,
            'quartier' => $artisan?->quartier,
            'specialite' => $fiche?->specialite,
            'noteMoyenne' => (float) ($fiche?->note_moyenne ?? 0),
            'nbMissionsTerminees' => $fiche?->nb_missions_terminees ?? 0,

            // Fenêtre et état (utile pour l'admin).
            'dateDebut' => $this->date_debut?->toIso8601String(),
            'dateFin' => $this->date_fin?->toIso8601String(),
            'actif' => (bool) $this->actif,
            'nbAffichages' => $this->nb_affichages,
            'nbClics' => $this->nb_clics,
            'creeLe' => $this->created_at?->toIso8601String(),
        ];
    }
}