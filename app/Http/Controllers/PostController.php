<?php

namespace App\Http\Controllers;

use App\Http\Resources\PostResource;
use App\Models\Post;
use App\Models\PostMedia;
use App\Services\MediaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Fil d'actualité (§5.1.4) : publications des artisans suivis, photos de
 * réalisations, likes et commentaires.
 */
class PostController extends Controller
{
    public function __construct(private MediaService $media) {}

    /**
     * Fil personnalisé : les publications des artisans suivis et les siennes.
     *
     * Sans abonnement, le fil bascule sur les publications récentes de la
     * plateforme — un fil vide à la première ouverture ne donne aucune raison
     * de revenir.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $utilisateur = $request->user();

        $suivis = $utilisateur->abonnementsArtisans()->pluck('users.id');

        $requete = Post::query()
            ->with(['artisan:id,nom,prenom,avatar_url', 'medias'])
            ->withLikeDe($utilisateur->id)
            ->latest();

        if ($request->boolean('abonnements') && $suivis->isNotEmpty()) {
            $requete->whereIn('artisan_id', $suivis->push($utilisateur->id));
        }

        // Portfolio d'un artisan precis (§5.1.6). Sans ce filtre, un client
        // consultant une fiche ne pouvait pas voir ses realisations : seul le
        // fil global etait interrogeable.
        if ($request->filled('artisan')) {
            $requete->where('artisan_id', $request->integer('artisan'));
        }

        return PostResource::collection($requete->cursorPaginate(15));
    }

    public function store(Request $request): JsonResponse
    {
        $donnees = $request->validate([
            'artisan_category' => 'required|string|max:255',
            'description' => 'required|string|max:2000',
            // Une image seule, ou une galerie : les deux formes sont acceptées.
            'image_url' => 'required_without:medias|string',
            'medias' => 'required_without:image_url|array|min:1|max:6',
            'medias.*' => 'string',
        ]);

        $sources = $donnees['medias'] ?? [$donnees['image_url']];

        try {
            $urls = array_map(
                fn (string $source) => $this->media->enregistrerImage($source, 'publications'),
                $sources,
            );
        } catch (RuntimeException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'errors' => ['medias' => [$e->getMessage()]],
            ], 422);
        }

        $post = DB::transaction(function () use ($donnees, $request, $urls) {
            // L'auteur vient du jeton, jamais du corps de la requête.
            $post = Post::create([
                'artisan_id' => $request->user()->id,
                'artisan_category' => $donnees['artisan_category'],
                'description' => $donnees['description'],
                'image_url' => $urls[0],
                'likes_count' => 0,
                'comments_count' => 0,
            ]);

            foreach ($urls as $ordre => $url) {
                PostMedia::create(['post_id' => $post->id, 'url' => $url, 'ordre' => $ordre]);
            }

            return $post;
        });

        return response()->json([
            'message' => 'Publication enregistrée.',
            'post' => new PostResource($post->load(['artisan:id,nom,prenom,avatar_url', 'medias'])),
        ], 201);
    }

    /** Une publication ne se supprime que par son auteur ou l'administration. */
    public function destroy(Request $request, Post $post): JsonResponse
    {
        $utilisateur = $request->user();

        if ($post->artisan_id !== $utilisateur->id && ! $utilisateur->estAdmin()) {
            return response()->json(['message' => 'Accès refusé.'], 403);
        }

        $post->delete();

        return response()->json(['message' => 'Publication supprimée.']);
    }
}
