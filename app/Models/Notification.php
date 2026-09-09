<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $artisan_id destinataire de la notification
 * @property Carbon|null $lu_at
 */
class Notification extends Model
{
    use HasFactory;

    protected $fillable = [
        'artisan_id',
        'title',
        'body',
        'is_read',
        // Ces quatre colonnes existaient en base mais pas ici : elles étaient
        // silencieusement ignorées à l'écriture.
        'type',
        'canal',
        'donnees',
        'envoye_at',
        'lu_at',
        'repli_sms_envoye',
    ];

    protected function casts(): array
    {
        return [
            'is_read' => 'boolean',
            'donnees' => 'array',
            'lu_at' => 'datetime',
            'repli_sms_envoye' => 'boolean',
            'envoye_at' => 'datetime',
        ];
    }

    public function destinataire(): BelongsTo
    {
        return $this->belongsTo(User::class, 'artisan_id');
    }
}
