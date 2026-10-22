<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Publicité affichée à l'ouverture de l'application.
 *
 * @property int $id
 * @property int $artisan_id
 * @property int|null $cree_par
 * @property string $titre
 * @property string|null $description
 * @property string|null $image_url
 * @property Carbon|null $date_debut
 * @property Carbon|null $date_fin
 * @property bool $actif
 * @property int $nb_affichages
 * @property int $nb_clics
 * @property-read User $artisan
 */
class Publicite extends Model
{
    protected $table = 'publicites';

    protected $fillable = [
        'artisan_id',
        'cree_par',
        'titre',
        'description',
        'image_url',
        'date_debut',
        'date_fin',
        'actif',
    ];

    protected function casts(): array
    {
        return [
            'date_debut' => 'datetime',
            'date_fin' => 'datetime',
            'actif' => 'boolean',
            'nb_affichages' => 'integer',
            'nb_clics' => 'integer',
        ];
    }

    public function artisan(): BelongsTo
    {
        return $this->belongsTo(User::class, 'artisan_id');
    }

    public function creePar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cree_par');
    }

    // ------------------------------------------------------------------
    // Scopes
    // ------------------------------------------------------------------

    /**
     * Publicités actuellement diffusables : actives, dans leur fenêtre
     * de dates, dont l'artisan est valide et non suspendu.
     */
    public function scopeEnCours(Builder $requete): Builder
    {
        $maintenant = now();

        return $requete
            ->where('actif', true)
            ->where(function (Builder $q) use ($maintenant) {
                $q->whereNull('date_debut')->orWhere('date_debut', '<=', $maintenant);
            })
            ->where(function (Builder $q) use ($maintenant) {
                $q->whereNull('date_fin')->orWhere('date_fin', '>=', $maintenant);
            });
    }
}