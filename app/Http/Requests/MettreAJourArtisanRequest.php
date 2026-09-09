<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class MettreAJourArtisanRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'specialite' => 'sometimes|string|max:255',
            'bio' => 'sometimes|nullable|string|max:2000',
            'adresse' => 'sometimes|string|max:255',
            'zone_intervention' => 'sometimes|nullable|string|max:255',
            'rayon_km' => 'sometimes|integer|min:1|max:200',
            'latitude' => 'sometimes|numeric|between:-90,90',
            'longitude' => 'sometimes|numeric|between:-180,180',
            'local_professionnel' => 'sometimes|boolean',

            'metiers' => 'sometimes|array|max:5',
            'metiers.*' => 'string|exists:metiers,slug',
        ];
    }
}
