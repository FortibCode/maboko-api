<?php

namespace App\Http\Resources\Admin;

use App\Models\VerificationIdentite;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

/**
 * Demande de vérification d'identité vue par l'administration (§4.5).
 *
 * Les pièces sont exposées par liens signés valables dix minutes : elles ne
 * sont jamais accessibles par une URL devinable (§7.1).
 *
 * @property-read VerificationIdentite $resource
 */
class VerificationResource extends JsonResource
{
    private const VALIDITE_LIEN_MINUTES = 10;

    public function toArray(Request $request): array
    {
        $utilisateur = $this->resource->user;

        return [
            'id' => $this->resource->id,
            'utilisateur' => [
                'id' => $utilisateur->id,
                'nomComplet' => trim(($utilisateur->prenom ?? '').' '.$utilisateur->nom),
                'email' => $utilisateur->email,
                'telephone' => $utilisateur->telephone,
                'role' => $utilisateur->role,
            ],
            'typePiece' => $this->resource->type_piece,
            'statut' => $this->resource->statut,
            'motifRejet' => $this->resource->motif_rejet,
            'deposeeLe' => $this->resource->created_at?->toIso8601String(),
            'pieces' => $this->liensSignes(),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function liensSignes(): array
    {
        $faces = [
            'recto' => $this->resource->chemin_recto,
            'verso' => $this->resource->chemin_verso,
            'selfie' => $this->resource->chemin_selfie,
        ];

        $liens = [];

        foreach ($faces as $face => $chemin) {
            if (! $chemin || ! Storage::disk('local')->exists($chemin)) {
                continue;
            }

            $liens[$face] = URL::temporarySignedRoute(
                'admin.piece',
                now()->addMinutes(self::VALIDITE_LIEN_MINUTES),
                ['verification' => $this->resource->id, 'face' => $face],
            );
        }

        return $liens;
    }
}
