<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CreerAvisRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'note' => 'required|integer|min:1|max:5',
            'commentaire' => 'sometimes|nullable|string|max:1500',
        ];
    }

    public function messages(): array
    {
        return [
            'note.min' => 'La note va de 1 à 5 étoiles.',
            'note.max' => 'La note va de 1 à 5 étoiles.',
        ];
    }
}
