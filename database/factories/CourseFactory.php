<?php

namespace Database\Factories;

use App\Models\Chauffeur;
use App\Models\Course;
use App\Models\User; // Correction : Import de User
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Course>
 */
class CourseFactory extends Factory
{
    protected $model = Course::class;

    public function definition(): array
    {
        return [
            'chauffeur_id' => Chauffeur::factory(),
            // Utilisation de User::factory() pour la cohérence
            'utilisateur_id' => User::factory()->state([
                'role' => 'client',
            ]),
            'lieu_depart' => fake()->streetAddress(),
            'lieu_arrivee' => fake()->streetAddress(),
            'prix' => fake()->randomFloat(2, 1000, 5000),
            'statut' => fake()->randomElement(['en_attente', 'en_cours', 'terminee']),
        ];
    }
}
