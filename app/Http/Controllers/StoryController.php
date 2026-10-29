<?php

namespace App\Http\Controllers;

use App\Models\Story;
use App\Models\StoryView;
use App\Services\MediaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class StoryController extends Controller
{
    public function __construct(private MediaService $media) {}

    public function index(): JsonResponse
    {
        $stories = Story::with('utilisateur:id,nom,prenom,avatar_url')
            ->actives()
            ->latest()
            ->get()
            ->groupBy('utilisateur_id');

        return response()->json(['data' => $stories]);
    }

    public function store(Request $request): JsonResponse
    {
        $donnees = $request->validate([
            'media_url' => 'required|string',
            'type' => 'sometimes|string|in:image,video',
        ]);

        try {
            // On enregistre l'image (base64) sur le serveur et on récupère l'URL
            $url = $this->media->enregistrerImage($donnees['media_url'], 'stories');
        } catch (RuntimeException $e) {
            return response()->json([
                'message' => 'Erreur lors de l\'enregistrement de l\'image : ' . $e->getMessage()
            ], 422);
        }

        $story = Story::create([
            'utilisateur_id' => $request->user()->id,
            'media_url' => $url,
            'type' => $donnees['type'] ?? 'image',
            'expires_at' => now()->addHours(2),
        ]);

        return response()->json(['message' => 'Story publiée.', 'story' => $story], 201);
    }

    public function mesStories(Request $request): JsonResponse
    {
        $stories = Story::where('utilisateur_id', $request->user()->id)
            ->actives()
            ->withCount('vues')
            ->latest()
            ->get();

        return response()->json(['data' => $stories]);
    }

    public function voirVues(Request $request, Story $story): JsonResponse
    {
        if ($story->utilisateur_id !== $request->user()->id) {
            return response()->json(['message' => 'Non autorisé.'], 403);
        }

        $vues = $story->vues()
            ->with('utilisateur:id,nom,prenom,avatar_url')
            ->latest()
            ->get()
            ->map(fn($vue) => [
                'id' => $vue->utilisateur->id,
                'nom' => $vue->utilisateur->nom,
                'prenom' => $vue->utilisateur->prenom,
                'avatarUrl' => $vue->utilisateur->avatar_url,
                'vu_le' => $vue->created_at->toIso8601String(),
            ]);

        return response()->json(['data' => $vues]);
    }

    public function marquerVue(Request $request, Story $story): JsonResponse
    {
        StoryView::firstOrCreate([
            'story_id' => $story->id,
            'user_id' => $request->user()->id,
        ]);

        return response()->json(['message' => 'Vue enregistrée.']);
    }

    public function destroy(Request $request, Story $story): JsonResponse
    {
        if ($story->utilisateur_id !== $request->user()->id) {
            return response()->json(['message' => 'Non autorisé.'], 403);
        }

        $story->delete();

        return response()->json(['message' => 'Story supprimée.']);
    }
}