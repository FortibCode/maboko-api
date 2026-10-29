<?php

namespace App\Models;

use App\Services\MediaService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Story : mise en avant d'un travail récent, visible 2 heures (§5.1.4).
 *
 * @property int $id
 * @property int $utilisateur_id
 * @property string $media_url
 * @property string $type
 * @property Carbon $expires_at
 * @property-read User $utilisateur
 */
class Story extends Model
{
    use HasFactory;

    protected $table = 'stories';

    protected $fillable = [
        'utilisateur_id',
        'media_url',
        'type',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
        ];
    }

    /**
     * L'utilisateur (artisan) qui a publié la story.
     */
    public function utilisateur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'utilisateur_id');
    }

    /**
     * Les vues de cette story.
     */
    public function vues(): HasMany
    {
        return $this->hasMany(StoryView::class, 'story_id');
    }

    /**
     * Ne remonte que les stories qui ne sont pas encore expirées (2h).
     */
    public function scopeActives(Builder $requete): Builder
    {
        return $requete->where('expires_at', '>', now());
    }

    /**
     * Rend l'URL absolue pour le client qui interroge l'API.
     */
    protected function mediaUrl(): Attribute
    {
        return Attribute::get(fn (?string $valeur) => MediaService::absolue($valeur));
    }
}