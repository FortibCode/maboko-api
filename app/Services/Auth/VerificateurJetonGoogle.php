<?php

namespace App\Services\Auth;

/**
 * Vérifie un jeton d'identité Google (ID token) et en extrait l'identité
 * authentifiée.
 *
 * Le jeton est un JWT signé par Google : c'est cette signature qui prouve que
 * l'e-mail appartient bien à l'appelant. Sans elle, un simple champ « email »
 * envoyé par le client se ferait passer pour n'importe qui.
 */
interface VerificateurJetonGoogle
{
    /**
     * @return array{sub: string, email: string, nom: ?string, prenom: ?string, avatarUrl: ?string}|null
     *                                                                                                   null si le jeton est absent, expiré, mal signé, ou si
     *                                                                                                   Google elle-même n'a pas vérifié l'adresse e-mail.
     */
    public function verifier(string $jetonId): ?array;
}
