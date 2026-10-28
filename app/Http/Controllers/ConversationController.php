<?php

namespace App\Http\Controllers;

use App\Events\MessageEnvoye;
use App\Http\Resources\MessageResource;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Services\MediaService;
use App\Services\ServiceNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class ConversationController extends Controller
{
    public function __construct(
        private MediaService $media,
        private ServiceNotification $notifications,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $conversations = $user->conversations()
            ->with(['participants:id,nom,prenom,avatar_url,role', 'dernierMessage'])
            ->orderByDesc('dernier_message_at')
            ->get();

        return response()->json(['data' => $conversations]);
    }

    public function store(Request $request): JsonResponse
    {
        $donnees = $request->validate([
            'participant_id' => 'required|integer|exists:users,id',
        ]);

        $user = $request->user();

        if ($donnees['participant_id'] === $user->id) {
            return response()->json(['message' => 'Vous ne pouvez pas vous écrire à vous-même.'], 422);
        }

        // Vérifier si une conversation privée existe déjà entre ces 2 utilisateurs
        $existante = $user->conversations()
            ->whereHas('participants', fn ($q) => $q->where('users.id', $donnees['participant_id']))
            ->where('type', 'privee')
            ->first();

        if ($existante) {
            return response()->json(['data' => $existante]);
        }

        $conversation = DB::transaction(function () use ($user, $donnees) {
            $conversation = Conversation::create([
                'type' => 'privee',
                'dernier_message_at' => now(),
            ]);

            $conversation->participants()->attach([$user->id, $donnees['participant_id']]);

            return $conversation;
        });

        return response()->json([
            'message' => 'Conversation créée.',
            'data' => $conversation->load('participants:id,nom,prenom,avatar_url,role'),
        ], 201);
    }

    public function support(Request $request): JsonResponse
    {
        // Méthode à implémenter si besoin
        return response()->json(['message' => 'Support non implémenté.'], 501);
    }

    public function messages(Request $request, Conversation $conversation): JsonResponse
    {
        $this->authorize('ecrire', $conversation);

        $messages = $conversation->messages()
            ->with('expediteur:id,nom,prenom,avatar_url')
            ->orderBy('created_at')
            ->get();

        return response()->json([
            'data' => MessageResource::collection($messages),
        ]);
    }

    public function envoyer(Request $request, Conversation $conversation): JsonResponse
    {
        $this->authorize('ecrire', $conversation);

        $conversation->loadMissing('participants:id,nom,prenom,telephone');

        $donnees = $request->validate([
            'contenu' => 'required_without:media|nullable|string|max:2000',
            'media' => 'required_without:contenu|nullable|string',
            'type_media' => 'sometimes|nullable|string|in:image,audio',
        ]);

        $mediaUrl = null;
        $typeMedia = $donnees['type_media'] ?? 'image';

        if (! empty($donnees['media'])) {
            try {
                $mediaUrl = $typeMedia === 'audio'
                    ? $this->media->enregistrerAudio($donnees['media'], 'messages')
                    : $this->media->enregistrerImage($donnees['media'], 'messages');
            } catch (RuntimeException $e) {
                return response()->json([
                    'message' => $e->getMessage(),
                    'errors' => ['media' => [$e->getMessage()]],
                ], 422);
            }
        }

        $message = DB::transaction(function () use ($conversation, $request, $donnees, $mediaUrl, $typeMedia) {
            $message = Message::create([
                'conversation_id' => $conversation->id,
                'expediteur_id' => $request->user()->id,
                'contenu' => $donnees['contenu'] ?? null,
                'media_url' => $mediaUrl,
                'type' => $mediaUrl ? $typeMedia : 'texte',
            ]);

            $conversation->update(['dernier_message_at' => $message->created_at]);

            $conversation->participants()->updateExistingPivot(
                $request->user()->id,
                ['dernier_message_lu_id' => $message->id],
            );

            return $message;
        });

        $message->load('expediteur:id,nom,prenom,avatar_url');

        MessageEnvoye::dispatch($message);

        $expediteurId = $request->user()->id;

        /** @var User $participant */
        foreach ($conversation->participants as $participant) {
            if ($participant->id === $expediteurId) {
                continue;
            }

            $resume = match ($message->type) {
                'audio' => 'Message vocal',
                'image' => 'Photo',
                default => Str::limit($message->contenu ?? '', 80),
            };

            $this->notifications->notifier(
                $participant,
                trim(($request->user()->prenom ?? '').' '.$request->user()->nom),
                $resume,
                type: 'message',
                donnees: ['conversation_id' => $conversation->id],
            );
        }

        return response()->json([
            'message' => 'Message envoyé.',
            'donnees' => new MessageResource($message),
        ], 201);
    }

    public function marquerLu(Request $request, Conversation $conversation): JsonResponse
    {
        $this->authorize('ecrire', $conversation);

        $dernierId = $conversation->messages()->max('id');

        if ($dernierId) {
            $conversation->participants()->updateExistingPivot(
                $request->user()->id,
                ['dernier_message_lu_id' => $dernierId],
            );
        }

        return response()->json(['message' => 'Conversation marquée comme lue.']);
    }
}