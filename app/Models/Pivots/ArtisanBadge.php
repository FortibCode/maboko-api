<?php

namespace App\Models\Pivots;

use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Support\Carbon;

/**
 * Attribution d'un badge de confiance a un artisan (§4.5).
 *
 * @property Carbon $obtenu_at
 * @property int|null $attribue_par null lorsque le badge est automatique
 */
class ArtisanBadge extends Pivot
{
    protected $table = 'artisan_badge';

    public $incrementing = true;

    protected function casts(): array
    {
        return ['obtenu_at' => 'datetime'];
    }
}
