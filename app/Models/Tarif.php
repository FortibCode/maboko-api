<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Grille tarifaire Allô Chauffeur, par type de vehicule.
 */
class Tarif extends Model
{
    protected $fillable = [
        'type_vehicule', 'prix_base', 'prix_km', 'prix_minute',
        'course_minimum', 'taux_commission', 'actif',
    ];

    protected function casts(): array
    {
        return [
            'prix_base' => 'decimal:2',
            'prix_km' => 'decimal:2',
            'prix_minute' => 'decimal:2',
            'course_minimum' => 'decimal:2',
            'taux_commission' => 'decimal:2',
            'actif' => 'boolean',
        ];
    }

    /** Tarif d'une course, plancher du minimum applique. */
    public function estimer(float $distanceKm, int $dureeMinutes = 0): float
    {
        $montant = (float) $this->prix_base
            + $distanceKm * (float) $this->prix_km
            + $dureeMinutes * (float) $this->prix_minute;

        return round(max($montant, (float) $this->course_minimum), 0);
    }
}
