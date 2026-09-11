<?php

namespace App\Models;

use App\Services\MediaService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Story : mise en avant d'un travail recent, visible 24 heures (§5.1.4).
 *
 * @property int $id
 * @property string $media_url
 * @property string|null $legende
 * @property Carbon $expire_at
 * @property-read User $artisan
 */
class Story extends Model
{
    protected $fillable = ['artisan_id', 'media_url', 'type', 'legende', 'vues_count', 'expire_at'];

    protected function casts(): array
    {
        return ['expire_at' => 'datetime'];
    }

    public function artisan(): BelongsTo
    {
        return $this->belongsTo(User::class, 'artisan_id');
    }

    /** Ne remonte que les stories encore valides. */
    public function scopeVisibles(Builder $requete): Builder
    {
        return $requete->where('expire_at', '>', now());
    }

    /**
     * Rend l'URL absolue pour le client qui interroge l'API.
     *
     * La colonne ne contient qu'un chemin : l'hote se decide a la lecture.
     */
    protected function mediaUrl(): Attribute
    {
        return Attribute::get(fn (?string $valeur) => MediaService::absolue($valeur));
    }
}
