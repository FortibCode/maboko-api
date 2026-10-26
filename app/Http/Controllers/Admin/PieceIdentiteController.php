<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\VerificationIdentite;
use App\Services\MediaService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Service des pièces d'identité à l'administration.
 *
 * Route signée et temporaire : le lien vaut dix minutes et ne peut pas être
 * forgé. Le fichier est déchiffré à la volée et n'est jamais écrit en clair
 * sur le disque (§7.1).
 */
class PieceIdentiteController extends Controller
{
    public function __construct(private MediaService $media) {}

    public function __invoke(Request $request, VerificationIdentite $verification, string $face): Response
    {
        abort_unless($request->hasValidSignature(), 403, 'Lien expiré ou invalide.');
        abort_unless($request->user()?->estAdmin(), 403);

        $chemin = match ($face) {
            'recto' => $verification->chemin_recto,
            'verso' => $verification->chemin_verso,
            'selfie' => $verification->chemin_selfie,
            default => null,
        };

        abort_if($chemin === null, 404);

        $contenu = $this->media->lirePiecePrivee($chemin);

        abort_if($contenu === null, 404, 'Pièce illisible.');

        return response($contenu, 200, [
            'Content-Type' => $this->media->typeMimeDe($chemin),
            // Affichage en ligne, mais jamais mis en cache : ces documents ne
            // doivent pas rester dans le cache du navigateur de l'administrateur.
            'Content-Disposition' => 'inline',
            'Cache-Control' => 'no-store, private',
        ]);
    }
}
