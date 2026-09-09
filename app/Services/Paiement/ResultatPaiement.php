<?php

namespace App\Services\Paiement;

use App\Models\Transaction;

/**
 * Réponse d'une passerelle à une demande de paiement.
 *
 * Un paiement Mobile Money est rarement immédiat : l'opérateur accuse
 * réception, le client confirme sur son téléphone, puis un webhook nous
 * informe. « enAttente » est donc le cas normal, pas une anomalie.
 */
class ResultatPaiement
{
    private function __construct(
        public readonly string $statut,
        public readonly ?string $referenceExterne = null,
        public readonly ?string $motifEchec = null,
        public readonly array $payload = [],
    ) {}

    public static function reussi(?string $referenceExterne = null, array $payload = []): self
    {
        return new self(Transaction::STATUT_REUSSIE, $referenceExterne, null, $payload);
    }

    public static function enAttente(?string $referenceExterne = null, array $payload = []): self
    {
        return new self(Transaction::STATUT_EN_ATTENTE, $referenceExterne, null, $payload);
    }

    public static function echoue(string $motif, array $payload = []): self
    {
        return new self(Transaction::STATUT_ECHOUEE, null, $motif, $payload);
    }

    public function estFinal(): bool
    {
        return $this->statut !== Transaction::STATUT_EN_ATTENTE;
    }
}
