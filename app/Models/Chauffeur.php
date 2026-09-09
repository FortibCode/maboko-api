<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property int $id
 * @property int $utilisateur_id
 * @property string $type_vehicule
 * @property string $vehicule_modele
 * @property string $plaque_immatriculation
 * @property bool $en_ligne
 * @property bool $disponibilite
 * @property string $statut_validation
 * @property int $nb_courses_terminees
 * @property float|null $distance_km Calculée par l'appariement
 * @property-read User $utilisateur
 * @property-read PositionChauffeur|null $dernierePosition
 */
class Chauffeur extends Model
{
    use HasFactory;

    public const VEHICULE_MOTO = 'moto';

    public const VEHICULE_VOITURE = 'voiture';

    protected $table = 'chauffeurs';

    protected $fillable = [
        'utilisateur_id', 'permis_conduire', 'vehicule_modele',
        'plaque_immatriculation', 'disponibilite', 'type_vehicule',
        'note_moyenne', 'nb_courses_terminees', 'statut_validation',
        'en_ligne', 'valide_at',
    ];

    protected function casts(): array
    {
        return [
            'disponibilite' => 'boolean',
            'en_ligne' => 'boolean',
            'note_moyenne' => 'decimal:2',
            'valide_at' => 'datetime',
        ];
    }

    public function utilisateur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'utilisateur_id');
    }

    public function courses(): HasMany
    {
        return $this->hasMany(Course::class, 'chauffeur_id');
    }

    public function positions(): HasMany
    {
        return $this->hasMany(PositionChauffeur::class);
    }

    public function dernierePosition(): HasOne
    {
        return $this->hasOne(PositionChauffeur::class)->latestOfMany('releve_at');
    }

    /** Chauffeurs joignables pour une nouvelle course. */
    public function scopeDisponibles(Builder $requete): Builder
    {
        return $requete->where('en_ligne', true)
            ->where('disponibilite', true)
            ->where('statut_validation', Artisan::VALIDATION_VALIDE);
    }
}
