<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Moyen de paiement Mobile Money enregistre par un utilisateur (§5.1.10).
 *
 * @property int $id
 * @property int $utilisateur_id
 * @property string $operateur
 * @property string $telephone
 * @property string|null $libelle
 * @property bool $par_defaut
 * @property-read User $utilisateur
 */
class MoyenPaiement extends Model
{
    protected $table = 'moyens_paiement';

    protected $fillable = [
        'utilisateur_id', 'operateur', 'telephone', 'libelle', 'par_defaut',
    ];

    protected function casts(): array
    {
        return ['par_defaut' => 'boolean'];
    }

    public function utilisateur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'utilisateur_id');
    }

    /**
     * Numero partiellement masque, pour l'affichage.
     *
     * Le titulaire reconnait son numero aux quatre derniers chiffres ; les
     * afficher en entier sur un ecran consulte en public n'apporte rien.
     */
    public function telephoneMasque(): string
    {
        $numero = $this->telephone;

        if (mb_strlen($numero) <= 4) {
            return $numero;
        }

        return str_repeat('•', 4).' '.mb_substr($numero, -4);
    }
}
