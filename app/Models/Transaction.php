<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * Paiement Mobile Money ou carte (§6.2).
 * Le payload brut de l'operateur est conserve : sans lui, aucune
 * reconciliation n'est possible en cas de litige.
 */
/**
 * @property int $id
 * @property string $reference_interne
 * @property string|null $reference_externe
 * @property string $statut
 * @property string $operateur
 * @property string $devise
 * @property string|null $motif_echec
 * @property array<string, mixed>|null $payload
 * @property Carbon|null $payee_at
 * @property Carbon|null $created_at
 */
class Transaction extends Model
{
    public const STATUT_INITIEE = 'initiee';

    public const STATUT_EN_ATTENTE = 'en_attente';

    public const STATUT_REUSSIE = 'reussie';

    public const STATUT_ECHOUEE = 'echouee';

    public const STATUT_REMBOURSEE = 'remboursee';

    public const OPERATEUR_AIRTEL = 'airtel';

    public const OPERATEUR_MTN = 'mtn';

    public const OPERATEUR_CARTE = 'carte';

    protected $fillable = [
        'user_id', 'payable_type', 'payable_id', 'montant', 'devise',
        'operateur', 'reference_interne', 'reference_externe',
        'statut', 'motif_echec', 'payload', 'payee_at',
    ];

    protected function casts(): array
    {
        return [
            'montant' => 'decimal:2',
            'payload' => 'array',
            'payee_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function payable(): MorphTo
    {
        return $this->morphTo();
    }

    public function commission(): HasOne
    {
        return $this->hasOne(Commission::class);
    }
}
