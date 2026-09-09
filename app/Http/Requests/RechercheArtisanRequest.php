<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Filtres de la recherche d'artisans (§4.1) : par metier, par zone
 * geographique et par niveau de competence.
 */
class RechercheArtisanRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'q' => 'sometimes|string|max:100',
            'metier' => 'sometimes|string|exists:metiers,slug',
            'ville' => 'sometimes|string|max:100',

            // La geolocalisation va par paire. « sometimes » est volontairement
            // absent : il court-circuiterait « required_with » lorsque le champ
            // manque, c'est-a-dire precisement le cas qu'on veut detecter.
            'latitude' => 'nullable|required_with:longitude|numeric|between:-90,90',
            'longitude' => 'nullable|required_with:latitude|numeric|between:-180,180',
            'rayon_km' => 'sometimes|integer|min:1|max:200',

            'note_min' => 'sometimes|numeric|min:0|max:5',
            'badge' => 'sometimes|string|exists:badges,slug',
            'tri' => ['sometimes', Rule::in(['pertinence', 'note', 'distance', 'missions'])],
            'par_page' => 'sometimes|integer|min:1|max:50',
        ];
    }

    public function messages(): array
    {
        return [
            'latitude.required_with' => 'La latitude est requise avec la longitude.',
            'longitude.required_with' => 'La longitude est requise avec la latitude.',
            'metier.exists' => 'Ce métier ne figure pas au référentiel.',
        ];
    }
}
