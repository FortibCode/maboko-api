<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property Carbon|null $releve_at
 */
class PositionChauffeur extends Model
{
    protected $table = 'positions_chauffeurs';

    protected $fillable = ['chauffeur_id', 'latitude', 'longitude', 'cap', 'vitesse_kmh', 'releve_at'];

    protected function casts(): array
    {
        return [
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'releve_at' => 'datetime',
        ];
    }

    public function chauffeur(): BelongsTo
    {
        return $this->belongsTo(Chauffeur::class);
    }
}
