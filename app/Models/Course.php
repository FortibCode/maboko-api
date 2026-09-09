<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Course extends Model
{
    use HasFactory;

    protected $table = 'courses';

    protected $fillable = [
        'chauffeur_id',
        'utilisateur_id', // Corrigé de client_id à utilisateur_id
        'lieu_depart',
        'lieu_arrivee',
        'prix',
        'statut',
    ];

    public function chauffeur(): BelongsTo
    {
        return $this->belongsTo(Chauffeur::class, 'chauffeur_id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(User::class, 'utilisateur_id');
    }
}
