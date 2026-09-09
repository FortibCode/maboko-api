<?php

namespace App\Http\Controllers;

use App\Http\Resources\CommentaireResource;
use App\Models\Commentaire;
use App\Models\Follow;
use App\Models\Like;
use App\Models\Post;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

/**
 * Likes, commentaires et abonnements du fil d'actualité (§4.1).
 *
 * Les compteurs « likes_count » et « comments_count » portés par les
 * publications n'étaient alimentés par rien : ils le sont désormais dans
 * la même transaction que l'interaction elle-même.
 */
class InteractionController extends Controller
{
    /** Ajoute ou retire un like, selon l'état actuel. */
    public function basculerLike(Request $request, Post $post): JsonResponse
    {
        $utilisateurId = $request->user()->id;

        $aime = DB::transaction(function () use ($post, $utilisateurId) {
            $like = Like::where('user_id', $utilisateurId)
                ->where('likeable_type', Post::class)
                ->where('likeable_id', $post->id)
                ->first();

            if ($like) {
                $like->delete();
                // whereKey est indispensable : appeler where() sur une instance
                // ouvre une requête sur toute la table, pas sur cette ligne.
                // Le garde-fou « > 0 » évite un compteur négatif si deux
                // requêtes concurrentes retirent le même like.
                Post::whereKey($post->id)
                    ->where('likes_count', '>', 0)
                    ->decrement('likes_count');

                return false;
            }

            Like::create([
                'user_id' => $utilisateurId,
                'likeable_type' => Post::class,
                'likeable_id' => $post->id,
            ]);
            $post->increment('likes_count');

            return true;
        });

        return response()->json([
            'aime' => $aime,
            'likesCount' => $post->fresh()->likes_count,
        ]);
    }

    public function commentaires(Post $post): AnonymousResourceCollection
    {
        $commentaires = $post->commentaires()
            ->where('statut_moderation', 'publie')
            ->whereNull('parent_id')
            ->with('auteur:id,nom,prenom,avatar_url,role')
            ->latest()
            ->cursorPaginate(20);

        return CommentaireResource::collection($commentaires);
    }

    public function commenter(Request $request, Post $post): JsonResponse
    {
        $donnees = $request->validate([
            'contenu' => 'required|string|max:1000',
            'parent_id' => 'sometimes|nullable|integer|exists:commentaires,id',
        ]);

        $commentaire = DB::transaction(function () use ($donnees, $request, $post) {
            $commentaire = Commentaire::create([
                'post_id' => $post->id,
                'user_id' => $request->user()->id,
                'parent_id' => $donnees['parent_id'] ?? null,
                'contenu' => $donnees['contenu'],
            ]);

            $post->increment('comments_count');

            return $commentaire;
        });

        return response()->json([
            'message' => 'Commentaire publié.',
            'commentaire' => new CommentaireResource($commentaire->load('auteur:id,nom,prenom,avatar_url,role')),
        ], 201);
    }

    public function supprimerCommentaire(Request $request, Commentaire $commentaire): JsonResponse
    {
        $utilisateur = $request->user();

        if ($commentaire->user_id !== $utilisateur->id && ! $utilisateur->estAdmin()) {
            return response()->json(['message' => 'Accès refusé.'], 403);
        }

        DB::transaction(function () use ($commentaire) {
            $postId = $commentaire->post_id;
            $commentaire->delete();

            Post::whereKey($postId)
                ->where('comments_count', '>', 0)
                ->decrement('comments_count');
        });

        return response()->json(['message' => 'Commentaire supprimé.']);
    }

    /** Suit ou cesse de suivre un artisan : alimente le fil personnalisé. */
    public function basculerAbonnement(Request $request, User $artisan): JsonResponse
    {
        $utilisateur = $request->user();

        if ($artisan->id === $utilisateur->id) {
            return response()->json(['message' => 'Vous ne pouvez pas vous suivre vous-même.'], 422);
        }

        $follow = Follow::where('follower_id', $utilisateur->id)
            ->where('artisan_id', $artisan->id)
            ->first();

        if ($follow) {
            $follow->delete();

            return response()->json(['message' => 'Abonnement retiré.', 'abonne' => false]);
        }

        Follow::create(['follower_id' => $utilisateur->id, 'artisan_id' => $artisan->id]);

        return response()->json(['message' => 'Vous suivez cet artisan.', 'abonne' => true], 201);
    }
}
