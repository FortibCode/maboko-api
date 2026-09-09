<?php

namespace App\Services\Paiement;

use App\Models\Transaction;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Passerelle de développement : aucun appel réseau.
 *
 * Permet de dérouler tout le parcours d'abonnement tant que les comptes
 * marchands Airtel et MTN ne sont pas ouverts — un délai de quatre à huit
 * semaines qu'il ne faut pas laisser bloquer le développement.
 *
 * Un numéro terminant par 0 échoue volontairement, pour éprouver le
 * traitement des refus sans dépendre de l'opérateur.
 */
class SimulationPasserelle implements PasserellePaiement
{
    public function initier(Transaction $transaction, string $telephone): ResultatPaiement
    {
        Log::info('[PAIEMENT simulation] débit demandé', [
            'reference' => $transaction->reference_interne,
            'montant' => $transaction->montant,
            'telephone' => $telephone,
        ]);

        if (str_ends_with($telephone, '0')) {
            return ResultatPaiement::echoue('Solde insuffisant (simulation).');
        }

        return ResultatPaiement::reussi('SIM-'.Str::upper(Str::random(12)), [
            'mode' => 'simulation',
            'telephone' => $telephone,
        ]);
    }

    public function verifier(Transaction $transaction): ResultatPaiement
    {
        return ResultatPaiement::reussi($transaction->reference_externe);
    }

    public function signatureValide(array $entetes, string $corps): bool
    {
        return true;
    }

    public function referenceDepuisNotification(array $donnees): ?string
    {
        return $donnees['reference'] ?? null;
    }

    public function statutDepuisNotification(array $donnees): ResultatPaiement
    {
        return ($donnees['statut'] ?? 'reussie') === 'reussie'
            ? ResultatPaiement::reussi($donnees['reference'] ?? null, $donnees)
            : ResultatPaiement::echoue($donnees['motif'] ?? 'Paiement refusé.', $donnees);
    }
}
