<?php

namespace Database\Factories;

use App\Models\Artisan;
use App\Models\DemandeDevis;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DemandeDevis>
 */
class DemandeDevisFactory extends Factory
{
    protected $model = DemandeDevis::class;

    public function definition(): array
    {
        return [
            'client_id' => User::factory(),
            'artisan_id' => Artisan::factory(),
            'titre' => 'Réparation de fuite',
            'description' => 'Fuite sous l évier de la cuisine depuis deux jours.',
            'adresse' => 'Bacongo, Brazzaville',
            'latitude' => -4.2894,
            'longitude' => 15.2429,
            'statut' => DemandeDevis::STATUT_EN_ATTENTE,
        ];
    }

    public function statut(string $statut): static
    {
        return $this->state(fn () => ['statut' => $statut]);
    }
}
