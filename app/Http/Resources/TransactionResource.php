<?php

namespace App\Http\Resources;

use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read Transaction $resource
 */
class TransactionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'reference' => $this->resource->reference_interne,
            'montant' => (float) $this->resource->montant,
            'devise' => $this->resource->devise,
            'operateur' => $this->resource->operateur,
            'statut' => $this->resource->statut,
            'motifEchec' => $this->resource->motif_echec,
            'payeeLe' => $this->resource->payee_at?->toIso8601String(),
            'creeeLe' => $this->resource->created_at?->toIso8601String(),
        ];
    }
}
