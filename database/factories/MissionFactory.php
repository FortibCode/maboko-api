<?php

namespace Database\Factories;

use App\Models\Artisan;
use Illuminate\Database\Eloquent\Factories\Factory;

class MissionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'artisan_id' => Artisan::factory(),
            'titre' => fake()->sentence(3),
            'description' => fake()->paragraph(),
            'prix_estime' => fake()->randomFloat(2, 5000, 50000), // Prix en Francs CFA
            'statut' => fake()->randomElement(['en_attente', 'en_cours', 'terminee']),
        ];
    }
}
