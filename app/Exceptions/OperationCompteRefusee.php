<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Une opération multi-comptes refusée pour une raison métier (limite
 * atteinte, compte archivé, compte déjà rattaché...). Le message est destiné
 * à l'utilisateur.
 */
class OperationCompteRefusee extends RuntimeException
{
    public function __construct(string $message, private int $statut = 422)
    {
        parent::__construct($message);
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json(['message' => $this->getMessage()], $this->statut);
    }
}
