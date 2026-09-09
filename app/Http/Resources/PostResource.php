<?php

namespace App\Http\Resources;

use App\Models\Post;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Publication du fil d'actualité (§5.1.4).
 *
 * @property-read Post $resource
 */
class PostResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        // artisan_id est obligatoire et contraint : l'auteur existe toujours.
        $auteur = $this->resource->artisan;

        return [
            'id' => $this->resource->id,
            'auteur' => [
                'id' => $auteur->id,
                'nomComplet' => trim(($auteur->prenom ?? '').' '.$auteur->nom) ?: 'Artisan Maboko',
                'avatarUrl' => $auteur->avatar_url,
                'metier' => $this->resource->artisan_category,
            ],
            'medias' => $this->resource->relationLoaded('medias') && $this->resource->medias->isNotEmpty()
                ? $this->resource->medias->pluck('url')
                // Les publications antérieures aux médias multiples ne portent
                // qu'une image, sur la colonne historique.
                : [$this->resource->image_url],
            'description' => $this->resource->description,
            'likesCount' => $this->resource->likes_count,
            'commentsCount' => $this->resource->comments_count,
            // Calculé en une requête pour tout le fil, jamais post par post.
            'isLiked' => (bool) ($this->resource->aime_par_utilisateur ?? false),
            'publieLe' => $this->resource->created_at?->toIso8601String(),
        ];
    }
}
