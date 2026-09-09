<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Journal des actions d'administration : toute suspension de compte,
 * suppression de contenu ou validation doit rester opposable.
 */
class AuditLog extends Model
{
    protected $fillable = ['user_id', 'action', 'cible_type', 'cible_id', 'avant', 'apres', 'adresse_ip'];

    protected function casts(): array
    {
        return ['avant' => 'array', 'apres' => 'array'];
    }

    public function auteur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function cible(): MorphTo
    {
        return $this->morphTo();
    }
}
