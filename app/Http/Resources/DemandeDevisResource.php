<?php

namespace App\Http\Resources;

use App\Models\DemandeDevis;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Demande de devis (§5.1.7 et §5.2.2).
 *
 * @property-read DemandeDevis $resource
 */
class DemandeDevisResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'titre' => $this->resource->titre,
            'description' => $this->resource->description,
            'statut' => $this->resource->statut,

            'adresse' => $this->resource->adresse,
            'latitude' => $this->resource->latitude ? (float) $this->resource->latitude : null,
            'longitude' => $this->resource->longitude ? (float) $this->resource->longitude : null,

            'budgetEstime' => $this->resource->budget_estime ? (float) $this->resource->budget_estime : null,
            'montantPropose' => $this->resource->montant_propose ? (float) $this->resource->montant_propose : null,
            'montantFinal' => $this->resource->montant_final ? (float) $this->resource->montant_final : null,
            'dateSouhaitee' => $this->resource->date_souhaitee?->toDateString(),

            'photos' => $this->whenLoaded('photos', fn () => $this->resource->photos->pluck('url')),
            'client' => new UtilisateurResource($this->whenLoaded('client')),
            'artisan' => new ArtisanResource($this->whenLoaded('artisan')),
            'metier' => new MetierResource($this->whenLoaded('metier')),

            'motifRefus' => $this->resource->motif_refus,
            'accepteeLe' => $this->resource->acceptee_at?->toIso8601String(),
            'termineeLe' => $this->resource->terminee_at?->toIso8601String(),
            'creeeLe' => $this->resource->created_at?->toIso8601String(),

            'peutEtreNotee' => $this->resource->statut === DemandeDevis::STATUT_TERMINEE
                && $this->resource->relationLoaded('avis')
                && $this->resource->avis === null,
        ];
    }
}
