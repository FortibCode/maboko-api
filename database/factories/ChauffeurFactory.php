<?php

namespace Database\Factories;

use App\Models\Chauffeur;
use App\Models\User; // Correction : Import de User
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Chauffeur>
 */
class ChauffeurFactory extends Factory
{
    protected $model = Chauffeur::class;

    public function definition(): array
    {
        return [
            // Utilisation de User::factory() et définition du rôle pour la cohérence
            'utilisateur_id' => User::factory()->state([
                'role' => 'chauffeur',
            ]),
            // Sans valeur explicite, la fabrique laissait la colonne à null
            // et toute course créée à partir du chauffeur échouait.
            'type_vehicule' => fake()->randomElement([Chauffeur::VEHICULE_MOTO, Chauffeur::VEHICULE_VOITURE]),
            'permis_conduire' => fake()->unique()->bothify('PERM-#####'),
            'vehicule_modele' => fake()->randomElement(['Toyota Corolla', 'Renault Logan', 'Hyundai Accent']),
            'plaque_immatriculation' => fake()->unique()->bothify('??-###-??'),
            'disponibilite' => fake()->boolean(80),
        ];
    }
}
