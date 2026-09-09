<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property Carbon $debut
 * @property Carbon $fin
 * @property string $statut
 * @property bool $renouvellement_auto
 * @property-read Plan|null $plan
 * @property-read Artisan|null $artisan
 */
class Abonnement extends Model
{
    public const STATUT_ACTIF = 'actif';

    public const STATUT_EXPIRE = 'expire';

    public const STATUT_ANNULE = 'annule';

    public const STATUT_IMPAYE = 'impaye';

    protected $fillable = [
        'artisan_id', 'plan_id', 'periodicite', 'debut', 'fin',
        'statut', 'renouvellement_auto', 'derniere_relance_at',
    ];

    protected function casts(): array
    {
        return [
            'debut' => 'date',
            'fin' => 'date',
            'renouvellement_auto' => 'boolean',
            'derniere_relance_at' => 'datetime',
        ];
    }

    public function artisan(): BelongsTo
    {
        return $this->belongsTo(Artisan::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function estActif(): bool
    {
        return $this->statut === self::STATUT_ACTIF && $this->fin->isFuture();
    }
}
