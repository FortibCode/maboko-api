<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\MetierResource;
use App\Models\Metier;
use App\Services\JournalAdministration;
use App\Services\MediaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Administration du référentiel des métiers (§5.4).
 *
 * Création, modification, suppression et activation/désactivation des métiers.
 */
class MetierController extends Controller
{
    public function __construct(
        private JournalAdministration $journal,
        private MediaService $media,
    ) {}

    /**
     * Remplace la photo transmise en base64 par le chemin du fichier ecrit.
     *
     * Une chaine vide retire la photo ; l'absence du champ la laisse en
     * place, pour qu'un enregistrement du formulaire sans nouvelle image
     * n'efface pas celle qui existe.
     *
     * @param  array<string, mixed>  $donnees
     * @return array<string, mixed>
     */
    private function rangerImage(array $donnees): array
    {
        if (! array_key_exists('image', $donnees)) {
            return $donnees;
        }

        $image = $donnees['image'];
        unset($donnees['image']);

        $donnees['image_url'] = ($image === null || $image === '')
            ? null
            : $this->media->enregistrerImage((string) $image, 'metiers');

        return $donnees;
    }

    /** Liste complète des métiers pour l'administration. */
    public function index(Request $request): AnonymousResourceCollection
    {
        $recherche = $request->string('q')->trim()->value();

        $metiers = Metier::query()
            ->when($recherche !== '', fn ($r) => $r->where('nom', 'like', "%{$recherche}%"))
            ->withCount('artisans')
            ->orderBy('ordre')
            ->orderBy('nom')
            ->paginate(30);

        return MetierResource::collection($metiers);
    }

    /** Création d'un métier. */
    public function store(Request $request): JsonResponse
    {
        $donnees = $request->validate([
            'nom' => 'required|string|max:100|unique:metiers,nom',
            'slug' => 'sometimes|nullable|string|max:100|unique:metiers,slug',
            'icone' => 'sometimes|nullable|string|max:100',
            'image' => 'sometimes|nullable|string',
            'description' => 'sometimes|nullable|string|max:1000',
            'ordre' => 'sometimes|integer|min:0',
            'actif' => 'sometimes|boolean',
        ]);

        $donnees = $this->rangerImage($donnees);
        $donnees['slug'] = $donnees['slug'] ?? Str::slug($donnees['nom']);
        $donnees['actif'] = $donnees['actif'] ?? true;
        $donnees['ordre'] = $donnees['ordre'] ?? 0;

        $metier = Metier::create($donnees);

        Cache::flush();

        $this->journal->enregistrer(
            'metier.cree',
            $metier,
            [],
            $donnees,
        );

        return response()->json([
            'message' => 'Métier créé avec succès.',
            'metier' => new MetierResource($metier),
        ], 201);
    }

    /** Modification d'un métier. */
    public function update(Request $request, Metier $metier): JsonResponse
    {
        $donnees = $request->validate([
            'nom' => "sometimes|required|string|max:100|unique:metiers,nom,{$metier->id}",
            'slug' => "sometimes|nullable|string|max:100|unique:metiers,slug,{$metier->id}",
            'icone' => 'sometimes|nullable|string|max:100',
            'image' => 'sometimes|nullable|string',
            'description' => 'sometimes|nullable|string|max:1000',
            'ordre' => 'sometimes|integer|min:0',
            'actif' => 'sometimes|boolean',
        ]);

        $donnees = $this->rangerImage($donnees);

        if (isset($donnees['nom']) && ! isset($donnees['slug'])) {
            $donnees['slug'] = Str::slug($donnees['nom']);
        }

        $avant = $metier->toArray();
        $metier->update($donnees);

        Cache::flush();

        $this->journal->enregistrer(
            'metier.modifie',
            $metier,
            $avant,
            $donnees,
        );

        return response()->json([
            'message' => 'Métier mis à jour.',
            'metier' => new MetierResource($metier),
        ]);
    }

    /** Suppression ou désactivation d'un métier. */
    public function destroy(Metier $metier): JsonResponse
    {
        if ($metier->artisans()->exists()) {
            // Désactivation si des artisans y sont liés
            $metier->update(['actif' => false]);
            Cache::flush();

            $this->journal->enregistrer('metier.desactive', $metier);

            return response()->json([
                'message' => 'Des artisans sont associés à ce métier : il a été désactivé au lieu d’être supprimé.',
                'statut' => 'desactive',
            ]);
        }

        $metier->delete();
        Cache::flush();

        $this->journal->enregistrer('metier.supprime', $metier);

        return response()->json(['message' => 'Métier supprimé.']);
    }
}
