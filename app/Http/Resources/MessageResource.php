<?php

namespace App\Http\Resources;

use App\Models\Message;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read Message $resource
 */
class MessageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'conversationId' => $this->resource->conversation_id,
            'contenu' => $this->resource->contenu,
            'mediaUrl' => $this->resource->media_url,
            'type' => $this->resource->type,
            'expediteur' => [
                'id' => $this->resource->expediteur_id,
                'nomComplet' => $this->whenLoaded(
                    'expediteur',
                    fn () => trim(($this->resource->expediteur->prenom ?? '').' '.$this->resource->expediteur->nom),
                ),
                'avatarUrl' => $this->whenLoaded('expediteur', fn () => $this->resource->expediteur->avatar_url),
            ],
            // Vrai lorsque c'est l'utilisateur courant qui a écrit le message :
            // l'application s'en sert pour aligner la bulle à droite.
            'deMoi' => $request->user()?->id === $this->resource->expediteur_id,
            'envoyeLe' => $this->resource->created_at?->toIso8601String(),
        ];
    }
}
