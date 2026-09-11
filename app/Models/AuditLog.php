<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * Journal des actions d'administration : toute suspension de compte,
 * suppression de contenu ou validation doit rester opposable.
 *
 * @property int $id
 * @property string $action
 * @property string|null $cible_type
 * @property int|null $cible_id
 * @property array<string, mixed>|null $avant
 * @property array<string, mixed>|null $apres
 * @property string|null $adresse_ip
 * @property Carbon|null $created_at
 * @property-read User|null $auteur
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
