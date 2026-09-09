<?php

namespace App\Services\Paiement;

use App\Models\Transaction;

interface PasserellePaiement
{
    /**
     * Demande le débit du numéro indiqué.
     * L'opérateur peut répondre immédiatement, ou notifier plus tard.
     */
    public function initier(Transaction $transaction, string $telephone): ResultatPaiement;

    /**
     * Interroge l'opérateur sur l'état d'une transaction.
     * Sert de filet quand le webhook ne parvient pas.
     */
    public function verifier(Transaction $transaction): ResultatPaiement;

    /**
     * Valide l'authenticité d'une notification reçue.
     * Sans cette vérification, n'importe qui pourrait déclarer un paiement reçu.
     */
    public function signatureValide(array $entetes, string $corps): bool;

    /** Identifiant de la transaction tel que l'opérateur le transmet. */
    public function referenceDepuisNotification(array $donnees): ?string;

    /** État final déduit d'une notification. */
    public function statutDepuisNotification(array $donnees): ResultatPaiement;
}
