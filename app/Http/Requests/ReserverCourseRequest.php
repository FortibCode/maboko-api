<?php

namespace App\Http\Requests;

use App\Models\Chauffeur;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Réservation d'une course (§5.1.8).
 */
class ReserverCourseRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'lieu_depart' => 'required|string|max:255',
            'lieu_arrivee' => 'required|string|max:255',
            'depart_latitude' => 'required|numeric|between:-90,90',
            'depart_longitude' => 'required|numeric|between:-180,180',
            'arrivee_latitude' => 'required|numeric|between:-90,90',
            'arrivee_longitude' => 'required|numeric|between:-180,180',
            'type_vehicule' => ['required', Rule::in([Chauffeur::VEHICULE_MOTO, Chauffeur::VEHICULE_VOITURE])],
            // Prix proposé par le client (§5.1.9) ; sans lui, l'estimation fait foi.
            'prix_client' => 'sometimes|nullable|numeric|min:100|max:1000000',
        ];
    }
}