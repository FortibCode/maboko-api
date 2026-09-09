<?php

namespace App\Models\Pivots;

use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * Rattachement d'un artisan a un metier du referentiel.
 *
 * @property string $niveau debutant, confirme ou expert
 * @property bool $principal metier mis en avant sur la fiche
 */
class ArtisanMetier extends Pivot
{
    protected $table = 'artisan_metier';

    public $incrementing = true;

    protected function casts(): array
    {
        return ['principal' => 'boolean'];
    }
}
