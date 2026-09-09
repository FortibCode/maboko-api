<?php

namespace App\Models;

use App\Models\Pivots\ArtisanBadge;
use App\Models\Pivots\ArtisanMetier;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $utilisateur_id
 * @property string|null $bio
 * @property string $statut_validation
 * @property int $nb_avis
 * @property int $nb_missions_terminees
 * @property bool $local_professionnel
 * @property Carbon|null $created_at
 * @property float|null $distance_km Calculee par le scope aProximite()
 * @property-read Abonnement|null $abonnementActif
 * @property-read Collection<int, Badge> $badges
 * @property-read User $utilisateur
 */
class Artisan extends Model
{
    use HasFactory;

    public const VALIDATION_EN_ATTENTE = 'en_attente';

    public const VALIDATION_VALIDE = 'valide';

    public const VALIDATION_REJETE = 'rejete';

    protected $table = 'artisans';

    protected $fillable = [
        'utilisateur_id', 'specialite', 'adresse', 'latitude', 'longitude',
        'bio', 'zone_intervention', 'rayon_km', 'note_moyenne', 'nb_avis',
        'nb_missions_terminees', 'score_classement', 'statut_validation',
        'local_professionnel', 'valide_at',
    ];

    protected function casts(): array
    {
        return [
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'note_moyenne' => 'decimal:2',
            'score_classement' => 'decimal:2',
            'local_professionnel' => 'boolean',
            'valide_at' => 'datetime',
        ];
    }

    // ------------------------------------------------------------------
    // Relations
    // ------------------------------------------------------------------

    public function utilisateur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'utilisateur_id');
    }

    public function metiers(): BelongsToMany
    {
        return $this->belongsToMany(Metier::class, 'artisan_metier')
            ->using(ArtisanMetier::class)
            ->withPivot('niveau', 'principal')
            ->withTimestamps();
    }

    /**
     * Les badges passent par un pivot : la table « badges » est un
     * referentiel commun, elle ne porte pas d'artisan_id.
     */
    public function badges(): BelongsToMany
    {
        return $this->belongsToMany(Badge::class, 'artisan_badge')
            ->using(ArtisanBadge::class)
            ->withPivot('obtenu_at', 'attribue_par')
            ->withTimestamps();
    }

    public function demandes(): HasMany
    {
        return $this->hasMany(DemandeDevis::class, 'artisan_id');
    }

    public function avis(): HasMany
    {
        return $this->hasMany(Avis::class, 'artisan_id');
    }

    public function abonnements(): HasMany
    {
        return $this->hasMany(Abonnement::class);
    }

    public function abonnementActif(): HasOne
    {
        return $this->hasOne(Abonnement::class)
            ->where('statut', Abonnement::STATUT_ACTIF)
            ->latestOfMany();
    }

    // ------------------------------------------------------------------
    // Recherche (§4.1)
    // ------------------------------------------------------------------

    /** N'expose que les artisans validés par l'administration. */
    public function scopeValides(Builder $requete): Builder
    {
        return $requete->where('statut_validation', self::VALIDATION_VALIDE);
    }

    public function scopeParMetier(Builder $requete, string $slug): Builder
    {
        return $requete->whereHas('metiers', fn (Builder $m) => $m->where('slug', $slug));
    }

    /**
     * Artisans situes dans un rayon donne, calcule par la formule de
     * haversine exprimee en SQL (portable PostgreSQL / MySQL / SQLite).
     */
    public function scopeAProximite(Builder $requete, float $lat, float $lng, int $rayonKm = 15): Builder
    {
        $distance = '(6371 * acos(cos(radians(?)) * cos(radians(latitude))'
            .' * cos(radians(longitude) - radians(?))'
            .' + sin(radians(?)) * sin(radians(latitude))))';

        return $requete
            ->selectRaw("artisans.*, {$distance} as distance_km", [$lat, $lng, $lat])
            ->whereRaw("{$distance} <= ?", [$lat, $lng, $lat, $rayonKm])
            ->orderBy('distance_km');
    }
}
