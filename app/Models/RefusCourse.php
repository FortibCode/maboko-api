<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Trace du refus d'une course par un chauffeur (§5.3.1).
 *
 * Elle sert a ne plus lui proposer la meme course, et donne a
 * l'administration une lecture des refus repetes.
 */
class RefusCourse extends Model
{
    protected $table = 'refus_courses';

    protected $fillable = ['course_id', 'chauffeur_id'];

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function chauffeur(): BelongsTo
    {
        return $this->belongsTo(Chauffeur::class);
    }
}
