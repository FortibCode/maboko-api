<?php

namespace App\Http\Controllers;

use App\Http\Requests\CreerAvisRequest;
use App\Http\Resources\AvisResource;
use App\Models\Avis;
use App\Models\DemandeDevis;
use App\Services\ClassementArtisan;
use App\Services\MoteurBadges;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Avis clients : « sécuriser la relation client-artisan grâce aux avis,
 * aux notes et à des profils vérifiés » (§2.2).
 */
class AvisController extends Controller
{
    public function __construct(
        private ClassementArtisan $classement,
        private MoteurBadges $badges,
    ) {}

    /**
     * Noter une intervention terminee. Un avis est adosse a une demande de
     * devis reellement realisee : on ne note pas un artisan que l'on n'a
     * jamais fait travailler.
     */
    public function store(CreerAvisRequest $request, DemandeDevis $demande): JsonResponse
    {
        $this->authorize('noter', $demande);

        if ($demande->avis()->exists()) {
            return response()->json([
                'message' => 'Vous avez déjà noté cette intervention.',
            ], 422);
        }

        $avis = Avis::create([
            'auteur_id' => $request->user()->id,
            'artisan_id' => $demande->artisan_id,
            'demande_devis_id' => $demande->id,
            'note' => $request->validated('note'),
            'commentaire' => $request->validated('commentaire'),
        ]);

        // La note moyenne et le classement de l'artisan sont recalcules
        // immediatement : c'est ce qui fait remonter les bons profils.
        // La note moyenne et le classement de l'artisan sont recalculés
        // immédiatement : c'est ce qui fait remonter les bons profils.
        $artisan = $demande->artisan;
        $this->classement->recalculer($artisan);
        // Un nouvel avis peut débloquer « Confirmé » ou « Recommandé ».
        $this->badges->reevaluer($artisan->fresh(['badges', 'abonnementActif.plan']));

        return response()->json([
            'message' => 'Merci, votre avis est publié.',
            'avis' => new AvisResource($avis->load('auteur:id,nom,prenom,avatar_url')),
        ], 201);
    }

    /** Signaler un avis a la moderation (§5.4.2). */
    public function signaler(Request $request, Avis $avis): JsonResponse
    {
        $avis->update(['statut_moderation' => 'signale']);

        if ($artisan = $avis->artisan) {
            $this->classement->recalculer($artisan);
        }

        return response()->json(['message' => 'Avis signalé à la modération.']);
    }
}
