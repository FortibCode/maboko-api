<?php

namespace Database\Factories;

use App\Models\Chauffeur;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Chauffeur>
 */
class ChauffeurFactory extends Factory
{
    protected $model = Chauffeur::class;

    public function definition(): array
    {
        // Choisit une combinaison de catégories une seule fois.
        $categories = fake()->randomElements(
            ['A', 'B', 'C', 'D', 'E'],
            fake()->numberBetween(1, 3),
        );

        return [
            'utilisateur_id' => User::factory()->state([
                'role' => 'chauffeur',
            ]),
            'type_vehicule' => fake()->randomElement([
                Chauffeur::VEHICULE_MOTO,
                Chauffeur::VEHICULE_VOITURE,
            ]),
            'permis_conduire' => fake()->unique()->bothify('PERM-#####'),
            'permis_categories' => implode(',', $categories),
            'vehicule_modele' => fake()->randomElement([
                'Toyota Corolla', 'Renault Logan', 'Hyundai Accent',
            ]),
            'plaque_immatriculation' => fake()->unique()->bothify('??-###-??'),
            'disponibilite' => fake()->boolean(80),
        ];
    }
}
