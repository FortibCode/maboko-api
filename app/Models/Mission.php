<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Mission extends Model
{
    use HasFactory;

    protected $table = 'missions';

    protected $fillable = [
        'artisan_id',
        'utilisateur_id', // Corrigé de client_id à utilisateur_id
        'titre',
        'description',
        'prix_estime',
        'statut',
    ];

    public function artisan(): BelongsTo
    {
        return $this->belongsTo(Artisan::class, 'artisan_id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(User::class, 'utilisateur_id');
    }
}
