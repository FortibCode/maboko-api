<?php

namespace App\Services\Push;

interface EnvoyeurPush
{
    /**
     * Envoie une notification aux appareils indiqués.
     *
     * @param  array<int, string>  $jetons
     * @param  array<string, string>  $donnees  charge utile pour la navigation
     * @return array{envoyes: int, jetons_invalides: array<int, string>}
     */
    public function envoyer(array $jetons, string $titre, string $corps, array $donnees = []): array;
}
