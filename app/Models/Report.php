<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Signalement de contenu (§5.4.2).
 *
 * @property int $id
 * @property string $statut
 * @property Carbon|null $traite_at
 * @property Carbon|null $created_at
 * @property-read User $reporter
 * @property-read User|null $moderateur
 */
class Report extends Model
{
    use HasFactory;

    protected $fillable = [
        'target_id',
        'target_type',
        'reason',
        'reporter_id',
        // Ajoutées par la migration de modération : sans elles, le traitement
        // d'un signalement était silencieusement ignoré.
        'statut',
        'traite_par',
        'traite_at',
        'decision',
    ];

    protected function casts(): array
    {
        return ['traite_at' => 'datetime'];
    }

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reporter_id');
    }

    public function moderateur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'traite_par');
    }
}
