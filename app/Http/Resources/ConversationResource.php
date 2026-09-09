<?php

namespace App\Http\Resources;

use App\Models\Conversation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Une conversation vue par l'un de ses participants (§5.1.9).
 *
 * @property-read Conversation $resource
 */
class ConversationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $moi = $request->user();

        // Dans un échange à deux, « l'autre » est le seul interlocuteur.
        $interlocuteur = $this->resource->relationLoaded('participants')
            ? $this->resource->participants->firstWhere('id', '!=', $moi?->id)
            : null;

        return [
            'id' => $this->resource->id,
            'type' => $this->resource->type,
            'estSupport' => $this->resource->type === Conversation::TYPE_SUPPORT,
            'interlocuteur' => $interlocuteur === null
                ? ['id' => null, 'nomComplet' => 'Support Maboko', 'avatarUrl' => null]
                : [
                    'id' => $interlocuteur->id,
                    'nomComplet' => trim(($interlocuteur->prenom ?? '').' '.$interlocuteur->nom),
                    'avatarUrl' => $interlocuteur->avatar_url,
                    'role' => $interlocuteur->role,
                ],
            'demandeId' => $this->resource->demande_devis_id,
            'dernierMessage' => $this->whenLoaded(
                'messages',
                fn () => $this->resource->messages->first()?->contenu,
            ),
            'dernierMessageLe' => $this->resource->dernier_message_at?->toIso8601String(),
            // Calculé par une sous-requête sur toute la liste, pas conversation
            // par conversation.
            'nonLus' => (int) ($this->resource->non_lus ?? 0),
        ];
    }
}
