<?php

namespace App\Http\Controllers;

use App\Models\Appareil;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Enregistrement des appareils pour les notifications push (§6.2).
 */
class AppareilController extends Controller
{
    public function enregistrer(Request $request): JsonResponse
    {
        $donnees = $request->validate([
            'jeton_push' => 'required|string|max:512',
            'plateforme' => ['required', Rule::in(['android', 'ios', 'web'])],
            'modele' => 'sometimes|nullable|string|max:120',
        ]);

        // Un jeton peut changer de main : réinstallation, changement de compte
        // sur le même téléphone. Il est donc rattaché au dernier connecté.
        $appareil = Appareil::updateOrCreate(
            ['jeton_push' => $donnees['jeton_push']],
            [
                'user_id' => $request->user()->id,
                'plateforme' => $donnees['plateforme'],
                'modele' => $donnees['modele'] ?? null,
                'derniere_activite_at' => now(),
            ],
        );

        return response()->json([
            'message' => 'Appareil enregistré.',
            'appareil' => ['id' => $appareil->id, 'plateforme' => $appareil->plateforme],
        ], $appareil->wasRecentlyCreated ? 201 : 200);
    }

    /** À la déconnexion : l'appareil ne doit plus recevoir les notifications. */
    public function retirer(Request $request): JsonResponse
    {
        $donnees = $request->validate(['jeton_push' => 'required|string']);

        Appareil::where('jeton_push', $donnees['jeton_push'])
            ->where('user_id', $request->user()->id)
            ->delete();

        return response()->json(['message' => 'Appareil retiré.']);
    }
}
