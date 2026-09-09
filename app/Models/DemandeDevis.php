<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * Demande de devis : le coeur de la marketplace (§5.1.7).
 * Remplace les tables « missions » et « bookings ».
 *
 * @property int $id
 * @property int $client_id
 * @property int $artisan_id
 * @property string $statut
 * @property Carbon|null $date_souhaitee
 * @property Carbon|null $acceptee_at
 * @property Carbon|null $terminee_at
 * @property Carbon|null $created_at
 * @property-read Artisan $artisan
 * @property-read User|null $client
 * @property-read Avis|null $avis
 */
class DemandeDevis extends Model
{
    use HasFactory;

    public const STATUT_EN_ATTENTE = 'en_attente';

    public const STATUT_ACCEPTEE = 'acceptee';

    public const STATUT_REFUSEE = 'refusee';

    public const STATUT_EN_COURS = 'en_cours';

    public const STATUT_TERMINEE = 'terminee';

    public const STATUT_ANNULEE = 'annulee';

    public const STATUTS = [
        self::STATUT_EN_ATTENTE, self::STATUT_ACCEPTEE, self::STATUT_REFUSEE,
        self::STATUT_EN_COURS, self::STATUT_TERMINEE, self::STATUT_ANNULEE,
    ];

    protected $table = 'demandes_devis';

    protected $fillable = [
        'client_id', 'artisan_id', 'metier_id', 'titre', 'description',
        'adresse', 'latitude', 'longitude', 'budget_estime', 'montant_propose',
        'montant_final', 'date_souhaitee', 'statut', 'motif_refus',
        'acceptee_at', 'terminee_at',
    ];

    protected function casts(): array
    {
        return [
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'budget_estime' => 'decimal:2',
            'montant_propose' => 'decimal:2',
            'montant_final' => 'decimal:2',
            'date_souhaitee' => 'date',
            'acceptee_at' => 'datetime',
            'terminee_at' => 'datetime',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(User::class, 'client_id');
    }

    public function artisan(): BelongsTo
    {
        return $this->belongsTo(Artisan::class, 'artisan_id');
    }

    public function metier(): BelongsTo
    {
        return $this->belongsTo(Metier::class, 'metier_id');
    }

    public function photos(): HasMany
    {
        return $this->hasMany(DemandePhoto::class, 'demande_devis_id')->orderBy('ordre');
    }

    public function avis(): HasOne
    {
        return $this->hasOne(Avis::class, 'demande_devis_id');
    }

    public function conversation(): HasOne
    {
        return $this->hasOne(Conversation::class, 'demande_devis_id');
    }

    public function estCloturee(): bool
    {
        return in_array($this->statut, [self::STATUT_TERMINEE, self::STATUT_ANNULEE, self::STATUT_REFUSEE], true);
    }
}
