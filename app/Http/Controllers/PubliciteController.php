<?php

namespace App\Http\Controllers;

use App\Http\Resources\PubliciteResource;
use App\Models\Publicite;
use App\Models\User;
use App\Services\JournalAdministration;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Gestion des publicités (§5.1.7).
 *
 * Côté mobile : renvoie la publicité à afficher au lancement.
 * Côté admin : création, activation et suivi des campagnes.
 */
class PubliciteController extends Controller
{
    public function __construct(
        private JournalAdministration $journal,
    ) {}

    /**
     * Publicité à afficher au lancement — côté mobile.
     *
     * Renvoie la plus récente publicité en cours, ou `null` s'il n'y en a
     * aucune. Le mobile gère lui-même la règle « une fois par jour ».
     */
    public function active(): JsonResponse
    {
        $publicite = Publicite::query()
            ->enCours()
            ->with([
                'artisan:id,nom,prenom,avatar_url,ville,quartier',
                'artisan.artisan:id,utilisateur_id,specialite,note_moyenne,nb_missions_terminees,bio',
            ])
            ->latest()
            ->first();

        if (! $publicite) {
            return response()->json(['publicite' => null]);
        }

        // Compteur d'affichage — best-effort, on ne bloque pas la réponse.
        $publicite->increment('nb_affichages');

        return response()->json([
            'publicite' => new PubliciteResource($publicite->fresh('artisan')),
        ]);
    }

    /**
     * Enregistre un clic sur la publicité.
     */
    public function clic(Publicite $publicite): JsonResponse
    {
        $publicite->increment('nb_clics');

        return response()->json(['message' => 'Clic enregistré.']);
    }

    /**
     * Liste toutes les publicités — côté admin.
     */
    public function index(): JsonResponse
    {
        $publicites = Publicite::query()
            ->with([
                'artisan:id,nom,prenom,avatar_url',
                'creePar:id,nom,prenom',
            ])
            ->orderByDesc('created_at')
            ->paginate(20);

        return response()->json($publicites);
    }

    /**
     * Crée une nouvelle publicité.
     */
    public function store(Request $request): JsonResponse
    {
        $donnees = $request->validate([
            'artisan_id' => 'required|integer|exists:users,id',
            'titre' => 'required|string|max:150',
            'description' => 'nullable|string|max:1000',
            'image_url' => 'nullable|string|max:500',
            'date_debut' => 'nullable|date',
            'date_fin' => 'nullable|date|after:date_debut',
            'actif' => 'sometimes|boolean',
        ]);

        // Vérifie que l'utilisateur ciblé est bien un artisan.
        $artisan = User::findOrFail($donnees['artisan_id']);

        if ($artisan->role !== User::ROLE_ARTISAN) {
            return response()->json([
                'message' => 'L\'utilisateur ciblé n\'est pas un artisan.',
                'errors' => ['artisan_id' => ['L\'utilisateur ciblé n\'est pas un artisan.']],
            ], 422);
        }

        $publicite = Publicite::create([
            ...$donnees,
            'cree_par' => $request->user()->id,
            'actif' => $donnees['actif'] ?? true,
        ]);

        $this->journal->enregistrer(
            'publicite.creee',
            $publicite,
            [],
            ['artisan_id' => $publicite->artisan_id, 'titre' => $publicite->titre],
        );

        return response()->json([
            'message' => 'Publicité créée.',
            'publicite' => new PubliciteResource($publicite->load('artisan')),
        ], 201);
    }

    /**
     * Modifie une publicité existante.
     */
    public function update(Request $request, Publicite $publicite): JsonResponse
    {
        $donnees = $request->validate([
            'titre' => 'sometimes|string|max:150',
            'description' => 'sometimes|nullable|string|max:1000',
            'image_url' => 'sometimes|nullable|string|max:500',
            'date_debut' => 'sometimes|nullable|date',
            'date_fin' => 'sometimes|nullable|date',
        ]);

        $avant = $publicite->only(['titre', 'description', 'date_debut', 'date_fin']);
        $publicite->update($donnees);

        $this->journal->enregistrer(
            'publicite.modifiee',
            $publicite,
            $avant,
            $publicite->only(['titre', 'description', 'date_debut', 'date_fin']),
        );

        return response()->json([
            'message' => 'Publicité mise à jour.',
            'publicite' => new PubliciteResource($publicite->fresh('artisan')),
        ]);
    }

    /**
     * Active ou désactive une publicité.
     */
    public function basculerActivation(Request $request, Publicite $publicite): JsonResponse
    {
        $donnees = $request->validate([
            'actif' => 'required|boolean',
        ]);

        $avant = $publicite->actif;
        $publicite->update(['actif' => $donnees['actif']]);

        $this->journal->enregistrer(
            $donnees['actif'] ? 'publicite.activee' : 'publicite.desactivee',
            $publicite,
            ['actif' => $avant],
            ['actif' => $donnees['actif']],
        );

        return response()->json([
            'message' => $donnees['actif'] ? 'Publicité activée.' : 'Publicité désactivée.',
            'actif' => $publicite->fresh()->actif,
        ]);
    }

    /**
     * Supprime définitivement une publicité.
     */
    public function destroy(Publicite $publicite): JsonResponse
    {
        $this->journal->enregistrer(
            'publicite.supprimee',
            $publicite,
            ['titre' => $publicite->titre, 'artisan_id' => $publicite->artisan_id],
            [],
        );

        $publicite->delete();

        return response()->json(['message' => 'Publicité supprimée.']);
    }
}
