<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Dépôt des pièces pour la vérification d'identité (§4.5).
 */
class DeposerPieceIdentiteRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'type_piece' => ['required', Rule::in(['cni', 'passeport', 'carte_consulaire'])],
            'numero_piece' => 'sometimes|nullable|string|max:60',
            'recto' => 'required|string',
            'verso' => 'sometimes|nullable|string',
            'selfie' => 'sometimes|nullable|string',
        ];
    }

    public function messages(): array
    {
        return [
            'type_piece.in' => 'Pièce acceptée : carte nationale d’identité, passeport ou carte consulaire.',
            'recto.required' => 'La photo recto de la pièce est obligatoire.',
        ];
    }
}
