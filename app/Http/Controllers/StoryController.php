<?php

namespace App\Http\Controllers;

use App\Http\Resources\StoryResource;
use App\Models\Story;
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
}
