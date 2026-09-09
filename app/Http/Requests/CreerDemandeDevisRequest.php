<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Formulaire de demande de devis (§5.1.7) : description du besoin,
 * photos a l'appui et adresse d'intervention.
 */
class CreerDemandeDevisRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'artisan_id' => 'required|integer|exists:artisans,id',
            'metier' => 'sometimes|nullable|string|exists:metiers,slug',

            'titre' => 'required|string|max:255',
            'description' => 'required|string|min:10|max:3000',

            'adresse' => 'required|string|max:255',
            'latitude' => 'sometimes|nullable|numeric|between:-90,90',
            'longitude' => 'sometimes|nullable|numeric|between:-180,180',

            'budget_estime' => 'sometimes|nullable|numeric|min:0|max:100000000',
            'date_souhaitee' => 'sometimes|nullable|date|after_or_equal:today',

            // Photos illustrant la demande : URL deja hebergee ou image base64.
            'photos' => 'sometimes|array|max:6',
            'photos.*' => 'string',
        ];
    }

    public function messages(): array
    {
        return [
            'description.min' => 'Décrivez votre besoin en quelques mots (10 caractères minimum).',
            'photos.max' => 'Six photos au maximum par demande.',
            'date_souhaitee.after_or_equal' => 'La date souhaitée ne peut pas être dans le passé.',
        ];
    }

    public function attributes(): array
    {
        return ['artisan_id' => 'artisan'];
    }
}
