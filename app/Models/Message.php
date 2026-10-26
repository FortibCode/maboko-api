<?php

namespace App\Models;

use App\Services\MediaService;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $conversation_id
 * @property int $expediteur_id
 * @property string|null $contenu
 * @property string|null $media_url
 * @property string $type
 * @property Carbon|null $created_at
 * @property-read User $expediteur
 */
class Message extends Model
{
    protected $fillable = ['conversation_id', 'expediteur_id', 'contenu', 'media_url', 'type'];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function expediteur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'expediteur_id');
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
