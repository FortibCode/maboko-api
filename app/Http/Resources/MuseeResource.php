<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Format d'une entrée du musée pour le mobile.
 */
class MuseeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $artisan = $this->artisan;
        $fiche = $artisan?->artisan;

        return [
            'id' => $this->id,
            'artisanId' => $artisan?->id,

            'nomComplet' => trim(($artisan?->prenom ?? '').' '.($artisan?->nom ?? '')),
            'avatarUrl' => $artisan?->avatar_url,
            'ville' => $artisan?->ville,
            'quartier' => $artisan?->quartier,

            'specialite' => $fiche?->specialite,
            'bio' => $fiche?->bio,
            'zoneIntervention' => $fiche?->zone_intervention,
            'noteMoyenne' => (float) ($fiche?->note_moyenne ?? 0),
            'nbMissionsTerminees' => $fiche?->nb_missions_terminees ?? 0,

            'metiers' => $fiche?->metiers?->map(fn ($m) => [
                'nom' => $m->nom,
                'slug' => $m->slug,
            ]) ?? [],

            'ordre' => $this->ordre,
            'valideAt' => $this->valide_at?->toIso8601String(),
        ];
    }
}
