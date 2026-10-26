<?php

namespace App\Http\Resources\Admin;

use App\Models\Post;
use App\Models\Report;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Signalement présenté à la modération (§5.4.2).
 *
 * Le contenu visé est joint : l'administrateur décide sur pièce, pas sur un
 * simple identifiant.
 *
 * @property-read Report $resource
 */
class SignalementResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $cible = $this->cible();

        return [
            'id' => $this->resource->id,
            'motif' => $this->resource->reason,
            'type' => $this->resource->target_type,
            'cibleId' => $this->resource->target_id,
            'contenu' => $this->contenu($cible),
            'contenuSupprime' => $cible === null,
            // reporter_id est obligatoire et contraint : l'auteur existe.
            'signalePar' => $this->whenLoaded(
                'reporter',
                fn () => trim(($this->resource->reporter->prenom ?? '').' '.$this->resource->reporter->nom),
            ),
            'statut' => $this->resource->statut,
            'signaleLe' => $this->resource->created_at?->toIso8601String(),
        ];
    }

    private function cible(): Post|User|null
    {
        return $this->resource->target_type === 'post'
            ? Post::with('artisan:id,nom,prenom')->find($this->resource->target_id)
            : User::find($this->resource->target_id);
    }

    private function contenu(Post|User|null $cible): ?array
    {
        if ($cible instanceof Post) {
            return [
                'description' => $cible->description,
                'image' => $cible->image_url,
                'auteur' => trim(($cible->artisan->prenom ?? '').' '.$cible->artisan->nom),
            ];
        }

        if ($cible instanceof User) {
            return [
                'nom' => trim(($cible->prenom ?? '').' '.$cible->nom),
                'email' => $cible->email,
            ];
        }

        return null;
    }
}
