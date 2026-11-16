<?php

namespace App\Policies;

use App\Models\User;

/**
 * Qui peut agir sur un compte de la famille (multi-comptes artisan).
 *
 * L'identifiant d'un compte envoyé par l'application n'est jamais une preuve :
 * seule compte l'appartenance du compte visé à la même personne que la
 * session en cours.
 */
class ComptePolicy
{
    /** Passer sur ce compte, le consulter ou le gérer. */
    public function gerer(User $acteur, User $compte): bool
    {
        return $acteur->titulaire()->id === $compte->titulaire()->id;
    }
}
