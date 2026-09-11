<?php

namespace App\Models;

use App\Services\MediaService;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PostMedia extends Model
{
    protected $table = 'post_medias';

    protected $fillable = ['post_id', 'url', 'type', 'ordre'];

    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }

    /**
     * Rend l'URL absolue pour le client qui interroge l'API.
     *
     * La colonne ne contient qu'un chemin : l'hote se decide a la lecture.
     */
    protected function url(): Attribute
    {
        return Attribute::get(fn (?string $valeur) => MediaService::absolue($valeur));
    }
}
