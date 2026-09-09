<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\VerificationIdentite;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Service des pièces d'identité à l'administration.
 *
 * Route signée et temporaire : le lien n'est valable que dix minutes et ne
 * peut pas être forgé. Les pièces ne sont jamais accessibles par une URL
 * devinable (§7.1).
 */
class PieceIdentiteController extends Controller
{
    public function __invoke(Request $request, VerificationIdentite $verification, string $face): StreamedResponse
    {
        abort_unless($request->hasValidSignature(), 403, 'Lien expiré ou invalide.');
        abort_unless($request->user()?->estAdmin(), 403);

        $chemin = match ($face) {
            'recto' => $verification->chemin_recto,
            'verso' => $verification->chemin_verso,
            'selfie' => $verification->chemin_selfie,
            default => null,
        };

        abort_if($chemin === null || ! Storage::disk('local')->exists($chemin), 404);

        return Storage::disk('local')->download($chemin);
    }
}
