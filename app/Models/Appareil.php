<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $user_id
 * @property string $jeton_push
 * @property string $plateforme
 */
class Appareil extends Model
{
    protected $table = 'appareils';

    protected $fillable = [
        'user_id',
        'jeton_push',
        'plateforme',
        'modele',
        'derniere_activite_at',
    ];

    protected function casts(): array
    {
        return ['derniere_activite_at' => 'datetime'];
    }

    public function utilisateur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
