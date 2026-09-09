<?php

namespace App\Http\Controllers;

use App\Models\Notification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    /**
     * Notifications du destinataire connecte.
     *
     * L'identifiant etait auparavant lu dans l'URL, ce qui permettait a
     * n'importe quel compte authentifie de consulter les notifications
     * d'un autre. Il est desormais deduit du jeton d'authentification.
     */
    public function index(Request $request): JsonResponse
    {
        $notifications = Notification::where('artisan_id', $request->user()->id)
            ->latest()
            ->paginate(30);

        return response()->json($notifications);
    }

    public function store(Request $request): JsonResponse
    {
        $donnees = $request->validate([
            'artisan_id' => 'required|exists:users,id',
            'title' => 'required|string|max:255',
            'body' => 'required|string|max:1000',
        ]);

        return response()->json([
            'message' => 'Notification creee.',
            'notification' => Notification::create($donnees),
        ], 201);
    }
}
