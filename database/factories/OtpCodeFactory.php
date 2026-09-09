<?php

namespace Database\Factories;

use App\Models\OtpCode;
use App\Models\User; // Correction : Import de User
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OtpCode>
 */
class OtpCodeFactory extends Factory
{
    protected $model = OtpCode::class;

    public function definition(): array
    {
        return [
            // Utilisation de User::factory()
            'utilisateur_id' => User::factory(),
            'code' => fake()->numerify('######'),
            'expires_at' => now()->addMinutes(10),
        ];
    }
}
