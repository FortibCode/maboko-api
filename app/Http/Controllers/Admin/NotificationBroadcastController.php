<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\JournalAdministration;
use App\Services\ServiceNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Diffusion de notifications groupées depuis le Back-Office (§5.4).
 */
class NotificationBroadcastController extends Controller
{
    public function __construct(
        private ServiceNotification $serviceNotification,
        private JournalAdministration $journal,
    ) {}

    public function diffuser(Request $request): JsonResponse
    {
        $donnees = $request->validate([
            'cible' => 'required|in:tous,clients,artisans,chauffeurs',
            'titre' => 'required|string|max:150',
            'corps' => 'required|string|max:1000',
            'important' => 'sometimes|boolean',
        ]);

        $requeteUsers = User::query()->where('statut', User::STATUT_ACTIF);

        // La règle de validation "in:tous,clients,artisans,chauffeurs" garantit
        // la valeur, mais son type reste "mixed" pour PHPStan : un dernier
        // arm par défaut couvre la valeur exhaustivement.
        match ($donnees['cible']) {
            'clients' => $requeteUsers->where('role', User::ROLE_CLIENT),
            'artisans' => $requeteUsers->where('role', User::ROLE_ARTISAN),
            'chauffeurs' => $requeteUsers->where('role', User::ROLE_CHAUFFEUR),
            default => null,
        };

        $utilisateurs = $requeteUsers->get();
        $total = 0;

        foreach ($utilisateurs as $user) {
            $this->serviceNotification->notifier(
                $user,
                $donnees['titre'],
                $donnees['corps'],
                type: 'admin_broadcast',
                important: $donnees['important'] ?? false,
            );
            $total++;
        }

        $this->journal->enregistrer(
            'notification.diffusee',
            null,
            [],
            [
                'cible' => $donnees['cible'],
                'titre' => $donnees['titre'],
                'destinataires' => $total,
            ],
        );

        return response()->json([
            'message' => "Notification diffusée avec succès à {$total} utilisateur(s).",
            'destinataires' => $total,
        ]);
    }
}
