<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Formules d'abonnement (§4.5) : gratuit, pro, premium, entreprise.
 */
/**
 * @property int $id
 * @property string $slug
 * @property string $nom
 * @property array<int, string>|null $avantages
 * @property Carbon|null $created_at
 */
class Plan extends Model
{
    public const GRATUIT = 'gratuit';

    public const PRO = 'pro';

    public const PREMIUM = 'premium';

    public const ENTREPRISE = 'entreprise';

    protected $fillable = [
        'slug', 'nom', 'description', 'prix_mensuel', 'prix_annuel',
        'avantages', 'boost_classement', 'ordre', 'actif',
    ];

    protected function casts(): array
    {
        return [
            'avantages' => 'array',
            'prix_mensuel' => 'decimal:2',
            'prix_annuel' => 'decimal:2',
            'boost_classement' => 'decimal:2',
            'actif' => 'boolean',
        ];
    }

    public function abonnements(): HasMany
    {
        return $this->hasMany(Abonnement::class);
    }
}
