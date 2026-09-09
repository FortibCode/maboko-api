<?php

namespace App\Http\Controllers;

use App\Models\Notification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    /**
     * Notifications du destinataire connecté.
     *
     * L'identifiant était auparavant lu dans l'URL, ce qui permettait à
     * n'importe quel compte authentifié de consulter celles d'un autre.
     */
    public function index(Request $request): JsonResponse
    {
        $notifications = Notification::where('artisan_id', $request->user()->id)
            ->latest()
            ->cursorPaginate(30);

        $notifications->through(fn (Notification $n) => [
            'id' => $n->id,
            'titre' => $n->title,
            'corps' => $n->body,
            'type' => $n->type,
            'donnees' => $n->donnees ?? [],
            'lue' => $n->lu_at !== null,
            'recueLe' => $n->created_at?->toIso8601String(),
        ]);

        return response()->json([
            'data' => $notifications->items(),
            'nonLues' => Notification::where('artisan_id', $request->user()->id)
                ->whereNull('lu_at')
                ->count(),
            'next_cursor' => $notifications->nextCursor()?->encode(),
        ]);
    }

    public function marquerLue(Request $request, Notification $notification): JsonResponse
    {
        if ($notification->artisan_id !== $request->user()->id) {
            return response()->json(['message' => 'Accès refusé.'], 403);
        }

        $notification->update(['lu_at' => now()]);

        return response()->json(['message' => 'Notification lue.']);
    }

    public function toutMarquerLu(Request $request): JsonResponse
    {
        Notification::where('artisan_id', $request->user()->id)
            ->whereNull('lu_at')
            ->update(['lu_at' => now()]);

        return response()->json(['message' => 'Toutes les notifications sont lues.']);
    }
}
