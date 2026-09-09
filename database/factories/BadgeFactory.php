<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class BadgeFactory extends Factory
{
    public function definition(): array
    {
        return [
            'nom' => fake()->randomElement(['Expert', 'Fiable', 'Rapide', 'Top Artisan']),
            'description' => fake()->sentence(),
            'icone' => 'badge_'.fake()->numberBetween(1, 5).'.png',
        ];
    }
}
