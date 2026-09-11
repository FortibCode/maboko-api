<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Aucune passerelle SMS n'est configuree alors qu'un code doit partir.
 *
 * Le canal « log » ecrit le code dans les journaux du serveur : utile en
 * developpement, sans aucun sens en production, ou l'utilisateur attend un
 * message sur son telephone. L'API repondait pourtant « Code de validation
 * envoye par SMS » — une inscription restait donc bloquee sur l'ecran du
 * code, sans que rien n'indique pourquoi.
 */
class PasserelleSmsAbsente extends RuntimeException
{
    public function __construct(
        string $message = 'L’envoi de SMS n’est pas configuré sur ce serveur. '
            .'Le code de validation ne peut pas être transmis.',
    ) {
        parent::__construct($message);
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'code' => 'passerelle_sms_absente',
        ], 503);
    }
}
