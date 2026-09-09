<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class UserFactory extends Factory
{
    protected $model = User::class;

    public function definition(): array
    {
        return [
            'nom' => fake()->lastName(),
            'prenom' => fake()->firstName(),
            'email' => fake()->unique()->safeEmail(),
            'telephone' => '+2420'.fake()->randomElement([4, 5, 6]).fake()->unique()->numerify('#######'),
            'password' => 'password',
            'role' => User::ROLE_CLIENT,
            'is_verified' => true,
            'statut' => User::STATUT_ACTIF,
            'ville' => 'Brazzaville',
            'quartier' => fake()->randomElement(['Bacongo', 'Poto-Poto', 'Moungali', 'Ouenze', 'Mpila', 'Talangai', 'Makelekele']),
        ];
    }

    public function artisan(): static
    {
        return $this->state(fn () => ['role' => User::ROLE_ARTISAN]);
    }

    public function chauffeur(): static
    {
        return $this->state(fn () => ['role' => User::ROLE_CHAUFFEUR]);
    }

    public function admin(): static
    {
        return $this->state(fn () => ['role' => User::ROLE_ADMIN]);
    }
}
