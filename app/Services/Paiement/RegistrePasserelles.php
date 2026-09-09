<?php

namespace App\Services\Paiement;

use App\Models\Transaction;
use InvalidArgumentException;

/**
 * Choisit la passerelle correspondant à l'opérateur.
 *
 * En mode « simulation », toutes les demandes passent par la passerelle de
 * développement, quel que soit l'opérateur choisi par l'utilisateur.
 */
class RegistrePasserelles
{
    public function pour(string $operateur): PasserellePaiement
    {
        if (config('paiement.mode') === 'simulation') {
            return new SimulationPasserelle;
        }

        return match ($operateur) {
            Transaction::OPERATEUR_AIRTEL => new AirtelMoneyPasserelle,
            Transaction::OPERATEUR_MTN => new MtnMoMoPasserelle,
            default => throw new InvalidArgumentException("Opérateur inconnu : {$operateur}"),
        };
    }

    public function estSimulation(): bool
    {
        return config('paiement.mode') === 'simulation';
    }
}
