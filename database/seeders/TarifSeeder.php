<?php

namespace Database\Seeders;

use App\Models\Chauffeur;
use App\Models\Tarif;
use Illuminate\Database\Seeder;

/**
 * Grille tarifaire Allô Chauffeur, en francs CFA.
 * Valeurs de depart a caler avec l'exploitation avant le lancement.
 */
class TarifSeeder extends Seeder
{
    public function run(): void
    {
        Tarif::updateOrCreate(['type_vehicule' => Chauffeur::VEHICULE_MOTO], [
            'prix_base' => 300,
            'prix_km' => 150,
            'prix_minute' => 10,
            'course_minimum' => 500,
            'taux_commission' => 15,
            'actif' => true,
        ]);

        Tarif::updateOrCreate(['type_vehicule' => Chauffeur::VEHICULE_VOITURE], [
            'prix_base' => 700,
            'prix_km' => 300,
            'prix_minute' => 20,
            'course_minimum' => 1500,
            'taux_commission' => 18,
            'actif' => true,
        ]);
    }
}
