<?php

namespace App\Http\Controllers;

use App\Models\VerificationIdentite;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class VerificationIdentiteController extends Controller
{
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

    public function deposer(Request $request): JsonResponse
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

        // On accepte les photos en base64 (comme le mobile les envoie)
        $donnees = $request->validate([
            'type_piece' => 'required|string|in:rccm,cni,passeport',
            'numero_piece' => 'nullable|string|max:100',
            'photo_recto' => 'required|string', // Le base64 envoyé par le mobile
            'photo_verso' => 'nullable|string', // Facultatif pour le RCCM
        ]);

        try {
            // On sauvegarde les photos en décodant le base64
            $cheminRecto = $this->sauvegarderBase64($donnees['photo_recto'], 'identites');
            
            $cheminVerso = empty($donnees['photo_verso']) 
                ? null 
                : $this->sauvegarderBase64($donnees['photo_verso'], 'identites');
        } catch (RuntimeException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        }

        VerificationIdentite::create([
            'user_id' => $utilisateur->id,
            'type_piece' => $donnees['type_piece'],
            'numero_piece' => $donnees['numero_piece'] ?? null,
            'statut' => 'en_attente',
            'chemin_recto' => $cheminRecto,
            'chemin_verso' => $cheminVerso,
            'chemin_selfie' => null,
        ]);

        return response()->json([
            'message' => 'Pièces reçues. Notre équipe les examine sous 48 heures.',
            'statut' => 'en_attente',
        ], 201);
    }

    /** Décode le base64 et sauvegarde le fichier sur le disque privé. */
    private function sauvegarderBase64(string $dataUrl, string $dossier): string
    {
        // On vérifie que c'est bien une image en base64
        if (!preg_match('/^data:image\/(\w+);base64,/', $dataUrl, $matches)) {
            throw new RuntimeException('Format d\'image invalide.');
        }

        $ext = $matches[1] === 'jpeg' ? 'jpg' : $matches[1];
        $contenu = base64_decode(substr($dataUrl, strpos($dataUrl, ',') + 1));

        if ($contenu === false) {
            throw new RuntimeException('Contenu base64 invalide.');
        }

        $nom = uniqid('id_', true) . '.' . $ext;
        Storage::disk('local')->put("{$dossier}/{$nom}", $contenu);

        return "{$dossier}/{$nom}";
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