<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Verification d'identite prealable au badge « Profil verifie » (§4.5).
 * Les chemins des pieces ne sont jamais exposes : elles sont servies
 * uniquement a l'administration, par URL signee et temporaire.
 */
/**
 * @property int $id
 * @property string $statut
 * @property string $type_piece
 * @property string|null $motif_rejet
 * @property Carbon|null $verifie_at
 * @property Carbon|null $created_at
 */
class VerificationIdentite extends Model
{
    protected $table = 'verifications_identite';

    protected $fillable = [
        'user_id', 'type_piece', 'numero_piece', 'chemin_recto', 'chemin_verso',
        'chemin_selfie', 'statut', 'verifie_par', 'verifie_at', 'motif_rejet',
    ];

    protected $hidden = ['chemin_recto', 'chemin_verso', 'chemin_selfie', 'numero_piece'];

    protected function casts(): array
    {
        return ['verifie_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function verificateur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verifie_par');
    }
}
