<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $statut
 * @property Carbon|null $created_at
 * @property-read User|null $auteur
 * @property-read User|null $responsable
 */
class Litige extends Model
{
    protected $fillable = [
        'ouvert_par', 'litigeable_type', 'litigeable_id', 'motif',
        'description', 'statut', 'assigne_a', 'resolution', 'resolu_at',
    ];

    protected function casts(): array
    {
        return ['resolu_at' => 'datetime'];
    }

    public function auteur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ouvert_par');
    }

    public function responsable(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigne_a');
    }

    public function litigeable(): MorphTo
    {
        return $this->morphTo();
    }
}
