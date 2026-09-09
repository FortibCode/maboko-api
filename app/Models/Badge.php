<?php

namespace App\Models;

use App\Models\Pivots\ArtisanBadge;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Referentiel des badges de confiance (§4.5).
 *
 * La table ne porte pas d'artisan_id : un badge est un libelle commun,
 * attribue aux artisans par le pivot « artisan_badge ».
 *
 * @property int $id
 * @property string $nom
 * @property string|null $slug
 * @property int $poids_classement
 * @property-read ArtisanBadge $pivot
 */
class Badge extends Model
{
    use HasFactory;

    protected $table = 'badges';

    protected $fillable = [
        'nom', 'slug', 'description', 'icone',
        'automatique', 'regle_attribution', 'poids_classement',
    ];

    protected function casts(): array
    {
        return [
            'automatique' => 'boolean',
            'regle_attribution' => 'array',
        ];
    }

    public function artisans(): BelongsToMany
    {
        return $this->belongsToMany(Artisan::class, 'artisan_badge')
            ->using(ArtisanBadge::class)
            ->withPivot('obtenu_at', 'attribue_par')
            ->withTimestamps();
    }
}
