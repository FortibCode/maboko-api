<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Course « Allô Chauffeur » (§4.3, §5.3).
 *
 * @property int $id
 * @property int|null $chauffeur_id
 * @property int|null $chauffeur_propose_id Chauffeur qui a fait une contre-proposition
 * @property int $utilisateur_id
 * @property string $statut
 * @property string $type_vehicule
 * @property float|null $distance_prise_en_charge Distance chauffeur → départ, ajoutée aux propositions
 * @property Carbon|null $acceptee_at
 * @property Carbon|null $prise_en_charge_at
 * @property Carbon|null $terminee_at
 * @property Carbon|null $created_at
 * @property-read Chauffeur|null $chauffeur
 * @property-read Chauffeur|null $chauffeurPropose
 * @property-read User $client
 */
class Course extends Model
{
    use HasFactory;

    /** Course créée, en attente qu'un chauffeur l'accepte. */
    public const STATUT_RECHERCHE = 'recherche';

    /**
     * Un chauffeur a proposé un autre prix : la course n'est plus offerte aux
     * autres chauffeurs tant que le client n'a pas accepté ou refusé.
     */
    public const STATUT_NEGOCIATION = 'negociation';

    public const STATUT_ACCEPTEE = 'acceptee';

    /** Le chauffeur roule vers le point de prise en charge. */
    public const STATUT_EN_ROUTE = 'en_route';

    /** Le client est à bord. */
    public const STATUT_PRISE_EN_CHARGE = 'prise_en_charge';

    public const STATUT_TERMINEE = 'terminee';

    public const STATUT_ANNULEE = 'annulee';

    public const STATUTS = [
        self::STATUT_RECHERCHE, self::STATUT_NEGOCIATION, self::STATUT_ACCEPTEE, self::STATUT_EN_ROUTE,
        self::STATUT_PRISE_EN_CHARGE, self::STATUT_TERMINEE, self::STATUT_ANNULEE,
    ];

    protected $table = 'courses';

    protected $fillable = [
        'chauffeur_id',
        'chauffeur_propose_id',
        'utilisateur_id',
        'lieu_depart',
        'lieu_arrivee',
        
        // 👇 AJOUT POUR LE TEST (colonnes de la table de base de données) 👇
        'depart_lat',
        'depart_lng',
        'arrivee_lat',
        'arrivee_lng',
        // 👆 FIN AJOUT 👆

        'depart_latitude',
        'depart_longitude',
        'arrivee_latitude',
        'arrivee_longitude',
        'type_vehicule',
        'distance_km',
        'duree_estimee_min',
        'tarif_estime',
        'tarif_final',
        'prix_client',
        'prix_chauffeur',
        'prix',
        'statut',
        'annulee_par',
        'motif_annulation',
        'acceptee_at',
        'prise_en_charge_at',
        'terminee_at',
    ];

    protected function casts(): array
    {
        return [
            'depart_latitude' => 'decimal:7',
            'depart_longitude' => 'decimal:7',
            'arrivee_latitude' => 'decimal:7',
            'arrivee_longitude' => 'decimal:7',
            'distance_km' => 'decimal:2',
            'tarif_estime' => 'decimal:2',
            'tarif_final' => 'decimal:2',
            'prix_client' => 'decimal:2',
            'prix_chauffeur' => 'decimal:2',
            'prix' => 'decimal:2',
            'acceptee_at' => 'datetime',
            'prise_en_charge_at' => 'datetime',
            'terminee_at' => 'datetime',
        ];
    }

    public function chauffeur(): BelongsTo
    {
        return $this->belongsTo(Chauffeur::class, 'chauffeur_id');
    }

    public function chauffeurPropose(): BelongsTo
    {
        return $this->belongsTo(Chauffeur::class, 'chauffeur_propose_id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(User::class, 'utilisateur_id');
    }

    public function estCloturee(): bool
    {
        return in_array($this->statut, [self::STATUT_TERMINEE, self::STATUT_ANNULEE], true);
    }

    /**
     * Prix retenu pour la course : celui du chauffeur si le client l'a
     * accepté, sinon celui proposé par le client, sinon l'estimation.
     */
    public function tarifConvenu(): float
    {
        return (float) ($this->prix_chauffeur ?? $this->prix_client ?? $this->tarif_estime);
    }

    /** Course en cours : elle occupe le chauffeur et suit le client. */
    public function estActive(): bool
    {
        return in_array($this->statut, [
            self::STATUT_ACCEPTEE, self::STATUT_EN_ROUTE, self::STATUT_PRISE_EN_CHARGE,
        ], true);
    }

    public function scopeActives(Builder $requete): Builder
    {
        return $requete->whereIn('statut', [
            self::STATUT_ACCEPTEE, self::STATUT_EN_ROUTE, self::STATUT_PRISE_EN_CHARGE,
        ]);
    }
}