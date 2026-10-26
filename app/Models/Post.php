<?php

namespace App\Models;

use App\Services\MediaService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $likes_count
 * @property int $comments_count
 * @property bool|null $aime_par_utilisateur Ajouté par le scope withLikeDe()
 * @property-read string|null $image_url URL absolue, construite à la lecture
 * @property Carbon|null $created_at
 * @property-read User|null $artisan
 */
class Post extends Model
{
    use HasFactory;

    protected $fillable = [
        'artisan_id', 'artisan_category', 'image_url',
        'description', 'likes_count', 'comments_count',
    ];

    public function artisan(): BelongsTo
    {
        return $this->belongsTo(User::class, 'artisan_id');
    }

    public function medias(): HasMany
    {
        return $this->hasMany(PostMedia::class)->orderBy('ordre');
    }

    public function commentaires(): HasMany
    {
        return $this->hasMany(Commentaire::class);
    }

    public function likes(): MorphMany
    {
        return $this->morphMany(Like::class, 'likeable');
    }

    /**
     * Indique, pour chaque publication du fil, si l'utilisateur l'a déjà aimée.
     *
     * Une sous-requête plutôt qu'un chargement de relation : le fil renvoie
     * quinze publications par page, et cela reste une seule requête.
     */
    public function scopeWithLikeDe(Builder $requete, int $utilisateurId): Builder
    {
        return $requete->addSelect([
            'aime_par_utilisateur' => Like::query()
                ->selectRaw('1')
                ->whereColumn('likeable_id', 'posts.id')
                ->where('likeable_type', self::class)
                ->where('user_id', $utilisateurId)
                ->limit(1),
        ]);
    }

    /**
     * Rend l'URL absolue pour le client qui interroge l'API.
     *
     * La colonne ne contient qu'un chemin : l'hote se decide a la lecture.
     */
    protected function imageUrl(): Attribute
    {
        return Attribute::get(fn (?string $valeur) => MediaService::absolue($valeur));
    }
}
