<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DemandePhoto extends Model
{
    protected $fillable = ['demande_devis_id', 'url', 'ordre'];

    public function demande(): BelongsTo
    {
        return $this->belongsTo(DemandeDevis::class, 'demande_devis_id');
    }
}
