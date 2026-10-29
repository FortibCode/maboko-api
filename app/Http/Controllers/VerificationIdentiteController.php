<?php

namespace App\Http\Controllers;

use App\Http\Requests\DeposerPieceIdentiteRequest;
use App\Models\VerificationIdentite;
use App\Services\MediaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Vérification d'identité, préalable au badge « Profil vérifié » (§4.5).
 *
 * Les pièces sont stockées sur le disque privé et ne sont jamais exposées
 * par l'API : seule l'administration y accède, depuis le back-office.
 */
class VerificationIdentiteController extends Controller
{
    public function __construct(private MediaService $media) {}

    /** État de la demande en cours. */
    public function afficher(Request $request): JsonResponse
    {
        $verification = VerificationIdentite::where('user_id', $request->user()->id)
            ->latest()
            ->first();

        if (! $verification) {
            return response()->json([
                'statut' => 'absente',
                'message' => 'Faites vérifier votre identité pour obtenir le badge « Profil vérifié ».',
            ]);
        }

        return response()->json([
            'statut' => $verification->statut,
            'typePiece' => $verification->type_piece,
            'deposeeLe' => $verification->created_at?->toIso8601String(),
            'verifieeLe' => $verification->verifie_at?->toIso8601String(),
            'motifRejet' => $verification->motif_rejet,
            'message' => $this->messagePour($verification->statut),
        ]);
    }

    public function deposer(DeposerPieceIdentiteRequest $request): JsonResponse
    {
        $utilisateur = $request->user();

        $enCours = VerificationIdentite::where('user_id', $utilisateur->id)
            ->whereIn('statut', ['en_attente', 'valide'])
            ->latest()
            ->first();

        if ($enCours) {
            return response()->json([
                'message' => $enCours->statut === 'valide'
                    ? 'Votre identité est déjà vérifiée.'
                    : 'Une demande est déjà en cours d’examen.',
            ], 422);
        }

        $donnees = $request->validated();

        try {
            $chemins = [
                'chemin_recto' => $this->media->enregistrerPiecePrivee($donnees['recto'], 'identites'),
                'chemin_verso' => empty($donnees['verso'])
                    ? null
                    : $this->media->enregistrerPiecePrivee($donnees['verso'], 'identites'),
                'chemin_selfie' => empty($donnees['selfie'])
                    ? null
                    : $this->media->enregistrerPiecePrivee($donnees['selfie'], 'identites'),
            ];
        } catch (RuntimeException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'errors' => ['recto' => [$e->getMessage()]],
            ], 422);
        }

        VerificationIdentite::create([
            'user_id' => $utilisateur->id,
            'type_piece' => $donnees['type_piece'],
            'numero_piece' => $donnees['numero_piece'] ?? null,
            'statut' => 'en_attente',
            ...$chemins,
        ]);

        return response()->json([
            'message' => 'Pièces reçues. Notre équipe les examine sous 48 heures.',
            'statut' => 'en_attente',
        ], 201);
    }

    private function messagePour(string $statut): string
    {
        return match ($statut) {
            'en_attente' => 'Vos pièces sont en cours d’examen.',
            'valide' => 'Votre identité est vérifiée. Le badge « Profil vérifié » apparaît sur votre fiche.',
            'rejete' => 'Vos pièces n’ont pas pu être validées. Vous pouvez en déposer de nouvelles.',
            default => '',
        };
    }
}
