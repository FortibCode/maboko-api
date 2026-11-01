<?php

namespace App\Http\Controllers;

use App\Http\Resources\StoryResource;
use App\Models\Story;
use App\Models\StoryVue;
use App\Services\MediaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use RuntimeException;

/**
 * Stories mettant en avant les travaux récents (§4.1, §5.1.4).
 * Elles restent visibles 24 heures.
 */
class StoryController extends Controller
{
    private const DUREE_HEURES = 24;

    public function __construct(private MediaService $media) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $suivis = $request->user()->abonnementsArtisans()->pluck('users.id');

        $stories = Story::visibles()
            ->with('artisan:id,nom,prenom,avatar_url')
            ->when(
                $suivis->isNotEmpty(),
                fn ($r) => $r->whereIn('artisan_id', $suivis->push($request->user()->id)),
            )
            ->latest()
            ->limit(50)
            ->get();

        return StoryResource::collection($stories);
    }

    public function store(Request $request): JsonResponse
    {
        $donnees = $request->validate([
            'media_url' => 'required|string',
            'legende' => 'sometimes|nullable|string|max:255',
        ]);

        try {
            $url = $this->media->enregistrerImage($donnees['media_url'], 'stories');
        } catch (RuntimeException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'errors' => ['media_url' => [$e->getMessage()]],
            ], 422);
        }

        $story = Story::create([
            'artisan_id' => $request->user()->id,
            'media_url' => $url,
            'legende' => $donnees['legende'] ?? null,
            'expire_at' => now()->addHours(self::DUREE_HEURES),
        ]);

        return response()->json([
            'message' => 'Story publiée pour 24 heures.',
            'story' => new StoryResource($story->load('artisan:id,nom,prenom,avatar_url')),
        ], 201);
    }

    /**
     * Stories encore visibles de l'artisan connecté, avec leur nombre de vues
     * (écran « Mes stories »).
     */
    public function mesStories(Request $request): JsonResponse
    {
        $stories = Story::visibles()
            ->where('artisan_id', $request->user()->id)
            ->latest()
            ->get()
            ->map(fn (Story $story) => [
                'id' => $story->id,
                'media_url' => $story->media_url,
                'legende' => $story->legende,
                'vues_count' => $story->vues_count,
                'expire_at' => $story->expire_at->toIso8601String(),
            ]);

        return response()->json(['data' => $stories]);
    }

    /** Personnes ayant vu la story, réservé à son auteur. */
    public function voirVues(Request $request, Story $story): JsonResponse
    {
        if ($story->artisan_id !== $request->user()->id) {
            return response()->json(['message' => 'Seul l\'auteur de la story voit qui l\'a regardée.'], 403);
        }

        $vues = $story->vues()
            ->with('utilisateur:id,nom,prenom,avatar_url')
            ->latest()
            ->get()
            ->map(fn (StoryVue $vue) => [
                'id' => $vue->utilisateur->id,
                'nom' => $vue->utilisateur->nom,
                'prenom' => $vue->utilisateur->prenom,
                'avatarUrl' => $vue->utilisateur->avatar_url,
                'vueLe' => $vue->created_at?->toIso8601String(),
            ]);

        return response()->json(['data' => $vues]);
    }

    /**
     * Enregistre que l'utilisateur connecté a vu la story. Une même personne
     * ne compte qu'une fois, et l'auteur ne se compte pas lui-même.
     */
    public function marquerVue(Request $request, Story $story): JsonResponse
    {
        $utilisateur = $request->user();

        if ($story->artisan_id !== $utilisateur->id) {
            $vue = StoryVue::firstOrCreate([
                'story_id' => $story->id,
                'user_id' => $utilisateur->id,
            ]);

            if ($vue->wasRecentlyCreated) {
                $story->increment('vues_count');
            }
        }

        return response()->json(['vuesCount' => $story->fresh()->vues_count]);
    }

    public function destroy(Request $request, Story $story): JsonResponse
    {
        if ($story->artisan_id !== $request->user()->id) {
            return response()->json(['message' => 'Seul l\'auteur peut supprimer cette story.'], 403);
        }

        $story->delete();

        return response()->json(['message' => 'Story supprimée.']);
    }
}
