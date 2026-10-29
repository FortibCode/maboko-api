<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Une entrée du musée du savoir-faire.
 *
 * @property int $id
 * @property int $artisan_id
 * @property string $statut
 * @property int|null $valide_par
 * @property Carbon|null $valide_at
 * @property Carbon|null $ignore_jusqua
 * @property int $ordre
 * @property-read User $artisan
 */
class MuseeSavoirFaire extends Model
{
    protected $table = 'musee_savoir_faire';

    // Statuts possibles.
    public const STATUT_CANDIDAT = 'candidat';

    public const STATUT_VALIDE = 'valide';

    public const STATUT_IGNORE = 'ignore';

    public const STATUT_BANNI = 'banni';

    protected $fillable = [
        'artisan_id',
        'statut',
        'valide_par',
        'valide_at',
        'ignore_jusqua',
        'ordre',
    ];

    protected function casts(): array
    {
        return [
            'valide_at' => 'datetime',
            'ignore_jusqua' => 'datetime',
        ];
    }

    public function artisan(): BelongsTo
    {
        return $this->belongsTo(User::class, 'artisan_id');
    }

    public function validePar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'valide_par');
    }

    // ------------------------------------------------------------------
    // Scopes
    // ------------------------------------------------------------------

    /** Les entrées visibles sur le mobile. */
    public function scopeValides(Builder $requete): Builder
    {
        return $requete->where('statut', self::STATUT_VALIDE);
    }

    /**
     * Candidats à proposer à l'admin : statut candidat, ou ignorés dont
     * le délai de 30 jours est écoulé.
     */
    public function scopeAProposer(Builder $requete): Builder
    {
        return $requete->where(function (Builder $q) {
            $q->where('statut', self::STATUT_CANDIDAT)
                ->orWhere(function (Builder $q2) {
                    $q2->where('statut', self::STATUT_IGNORE)
                        ->where('ignore_jusqua', '<=', now());
                });
        });
    }
}
