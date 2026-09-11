<?php

namespace App\Models;

use App\Services\MediaService;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DemandePhoto extends Model
{
    protected $fillable = ['demande_devis_id', 'url', 'ordre'];

    public function demande(): BelongsTo
    {
        return $this->belongsTo(DemandeDevis::class, 'demande_devis_id');
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
