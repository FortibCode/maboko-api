<?php

namespace App\Http\Resources;

use App\Models\Story;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Story : mise en avant d'un travail récent, visible 24 heures (§5.1.4).
 *
 * @property-read Story $resource
 */
class StoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $auteur = $this->resource->artisan;

        return [
            'id' => $this->resource->id,
            'auteur' => [
                'id' => $auteur->id,
                'nomComplet' => trim(($auteur->prenom ?? '').' '.$auteur->nom) ?: 'Artisan Maboko',
                'avatarUrl' => $auteur->avatar_url,
            ],
            'mediaUrl' => $this->resource->media_url,
            'legende' => $this->resource->legende,
            'expireLe' => $this->resource->expire_at->toIso8601String(),
        ];
    }
}
