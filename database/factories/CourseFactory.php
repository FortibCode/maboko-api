<?php

namespace Database\Factories;

use App\Models\Chauffeur;
use App\Models\Course;
use App\Models\User;
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
            'utilisateur_id' => User::factory(),
            'chauffeur_id' => null,
            'lieu_depart' => 'Bacongo, Brazzaville',
            'lieu_arrivee' => 'Mpila, Brazzaville',
            'depart_latitude' => -4.2894,
            'depart_longitude' => 15.2429,
            'arrivee_latitude' => -4.2610,
            'arrivee_longitude' => 15.2900,
            'type_vehicule' => Chauffeur::VEHICULE_MOTO,
            'distance_km' => 5.2,
            'duree_estimee_min' => 13,
            'tarif_estime' => 1210,
            'prix' => 1210,
            'statut' => Course::STATUT_RECHERCHE,
        ];
    }

    public function statut(string $statut): static
    {
        return $this->state(fn () => ['statut' => $statut]);
    }

    public function avecChauffeur(Chauffeur $chauffeur): static
    {
        return $this->state(fn () => [
            'chauffeur_id' => $chauffeur->id,
            'type_vehicule' => $chauffeur->type_vehicule,
        ]);
    }
}
