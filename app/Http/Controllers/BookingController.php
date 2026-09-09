<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BookingController extends Controller
{
    /**
     * Reservations de l'utilisateur connecte : celles qu'il a passees en tant
     * que client, et celles qu'il a recues en tant qu'artisan.
     *
     * La liste etait auparavant filtree par un identifiant fourni dans l'URL,
     * ce qui exposait les reservations de n'importe quel compte.
     */
    public function index(Request $request): JsonResponse
    {
        $utilisateur = $request->user();

        $reservations = Booking::with(['client:id,nom,prenom', 'artisan:id,nom,prenom'])
            ->where(function ($requete) use ($utilisateur) {
                $requete->where('user_id', $utilisateur->id)
                    ->orWhere('artisan_id', $utilisateur->id);
            })
            ->latest()
            ->cursorPaginate(30);

        return response()->json($reservations);
    }

    public function store(Request $request): JsonResponse
    {
        $donnees = $request->validate([
            'artisan_id' => 'required|exists:users,id',
            'service_title' => 'required|string|max:255',
            'amount' => 'required|numeric|min:0',
            'date' => 'required|date|after_or_equal:today',
        ]);

        $reservation = Booking::create([
            ...$donnees,
            'user_id' => $request->user()->id,
            'status' => 'pending',
        ]);

        return response()->json([
            'message' => 'Demande envoyee a l artisan.',
            'booking' => $reservation,
        ], 201);
    }

    /**
     * Changement de statut. Seuls le client et l'artisan concernes y ont acces,
     * et chacun ne peut declencher que les transitions qui le regardent.
     */
    public function updateStatus(Request $request, int $id): JsonResponse
    {
        $reservation = Booking::findOrFail($id);
        $utilisateur = $request->user();

        $donnees = $request->validate([
            'status' => 'required|string|in:pending,accepted,completed,cancelled',
        ]);

        $estArtisan = $reservation->artisan_id === $utilisateur->id;
        $estClient = $reservation->user_id === $utilisateur->id;

        if (! $estArtisan && ! $estClient) {
            return response()->json(['message' => 'Acces refuse.'], 403);
        }

        // L'artisan accepte ou termine ; le client peut seulement annuler.
        $autorises = $estArtisan
            ? ['accepted', 'completed', 'cancelled']
            : ['cancelled'];

        if (! in_array($donnees['status'], $autorises, true)) {
            return response()->json([
                'message' => 'Ce changement de statut ne vous est pas permis.',
            ], 403);
        }

        $reservation->update(['status' => $donnees['status']]);

        return response()->json([
            'message' => 'Statut mis a jour.',
            'booking' => $reservation,
        ]);
    }
}
