<?php

namespace Database\Factories;

use App\Models\Artisan;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class ArtisanFactory extends Factory
{
    protected $model = Artisan::class;

    public function definition(): array
    {
        return [
            'utilisateur_id' => User::factory()->artisan(),
            'specialite' => fake()->randomElement([
                'Macon', 'Plombier', 'Menuisier', 'Couturier', 'Mecanicien',
                'Electricien', 'Peintre', 'Frigoriste', 'Carreleur', 'Soudeur',
            ]),
            'adresse' => fake()->randomElement(['Bacongo', 'Poto-Poto', 'Moungali', 'Ouenze', 'Mpila']).', Brazzaville',
            // Coordonnees situees dans l'agglomeration de Brazzaville.
            'latitude' => fake()->randomFloat(7, -4.32, -4.22),
            'longitude' => fake()->randomFloat(7, 15.20, 15.32),
        ];
    }
}
