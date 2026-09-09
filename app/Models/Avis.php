<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Avis client : pilier de la confiance (§2.2), absent du modele initial.
 *
 * @property int $id
 * @property int $note
 * @property Carbon|null $created_at
 * @property-read Artisan|null $artisan
 */
class Avis extends Model
{
    use HasFactory;

    protected $table = 'avis';

    protected $fillable = [
        'auteur_id', 'artisan_id', 'demande_devis_id',
        'note', 'commentaire', 'statut_moderation',
    ];

    protected function casts(): array
    {
        return ['note' => 'integer'];
    }

    public function auteur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'auteur_id');
    }

    public function artisan(): BelongsTo
    {
        return $this->belongsTo(Artisan::class, 'artisan_id');
    }

    public function demande(): BelongsTo
    {
        return $this->belongsTo(DemandeDevis::class, 'demande_devis_id');
    }
}
