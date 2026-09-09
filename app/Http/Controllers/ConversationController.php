<?php

namespace App\Http\Controllers;

use App\Events\MessageEnvoye;
use App\Http\Resources\ConversationResource;
use App\Http\Resources\MessageResource;
use App\Models\Conversation;
use App\Models\DemandeDevis;
use App\Models\Message;
use App\Models\User;
use App\Services\MediaService;
use App\Services\ServiceNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Messagerie (§5.1.9) : échanges client-artisan et canal de support Maboko.
 */
class ConversationController extends Controller
{
    public function __construct(
        private MediaService $media,
        private ServiceNotification $notifications,
    ) {}

    /**
     * Liste des conversations, la plus récemment active en tête.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $utilisateur = $request->user();

        $conversations = Conversation::query()
            ->whereHas('participants', fn ($r) => $r->where('users.id', $utilisateur->id))
            ->with([
                'participants:id,nom,prenom,avatar_url,role',
                'messages' => fn ($r) => $r->latest()->limit(1),
            ])
            // Le nombre de non-lus est calculé en une sous-requête, pour toute
            // la liste : sinon c'est une requête par conversation affichée.
            ->withCount(['messages as non_lus' => function ($requete) use ($utilisateur) {
                $requete->where('expediteur_id', '!=', $utilisateur->id)
                    ->whereRaw(
                        'messages.id > coalesce((
                            select dernier_message_lu_id from conversation_participants
                            where conversation_id = conversations.id and user_id = ?
                        ), 0)',
                        [$utilisateur->id],
                    );
            }])
            ->orderByDesc('dernier_message_at')
            ->paginate(30);

        return ConversationResource::collection($conversations);
    }

    /**
     * Ouvre la conversation avec un interlocuteur, ou récupère l'existante.
     *
     * Une demande de devis n'a qu'un seul fil : le rattacher évite qu'un client
     * et un artisan finissent avec trois conversations pour le même chantier.
     */
    public function store(Request $request): JsonResponse
    {
        $donnees = $request->validate([
            'interlocuteur_id' => 'required_without:demande_id|integer|exists:users,id',
            'demande_id' => 'required_without:interlocuteur_id|integer|exists:demandes_devis,id',
        ]);

        $moi = $request->user();
        $demande = null;

        if (isset($donnees['demande_id'])) {
            $demande = DemandeDevis::with('artisan')->findOrFail($donnees['demande_id']);
            $this->authorize('view', $demande);

            $interlocuteurId = $demande->client_id === $moi->id
                ? $demande->artisan->utilisateur_id
                : $demande->client_id;
        } else {
            $interlocuteurId = (int) $donnees['interlocuteur_id'];
        }

        if (! $interlocuteurId || $interlocuteurId === $moi->id) {
            return response()->json(['message' => 'Interlocuteur introuvable.'], 422);
        }

        $existante = Conversation::query()
            ->when($demande, fn ($r) => $r->where('demande_devis_id', $demande->id))
            ->when(! $demande, fn ($r) => $r->whereNull('demande_devis_id'))
            ->whereHas('participants', fn ($r) => $r->where('users.id', $moi->id))
            ->whereHas('participants', fn ($r) => $r->where('users.id', $interlocuteurId))
            ->first();

        if ($existante) {
            return response()->json([
                'conversation' => new ConversationResource(
                    $existante->load('participants:id,nom,prenom,avatar_url,role'),
                ),
            ]);
        }

        $conversation = DB::transaction(function () use ($moi, $interlocuteurId, $demande) {
            $conversation = Conversation::create([
                'type' => Conversation::TYPE_CLIENT_ARTISAN,
                'demande_devis_id' => $demande?->id,
            ]);

            $conversation->participants()->attach([$moi->id, $interlocuteurId]);

            return $conversation;
        });

        return response()->json([
            'message' => 'Conversation ouverte.',
            'conversation' => new ConversationResource(
                $conversation->load('participants:id,nom,prenom,avatar_url,role'),
            ),
        ], 201);
    }

    public function messages(Request $request, Conversation $conversation): AnonymousResourceCollection
    {
        $this->authorize('view', $conversation);

        $messages = $conversation->messages()
            ->with('expediteur:id,nom,prenom,avatar_url')
            ->latest()
            ->cursorPaginate(30);

        return MessageResource::collection($messages);
    }

    public function envoyer(Request $request, Conversation $conversation): JsonResponse
    {
        $this->authorize('ecrire', $conversation);

        $conversation->loadMissing('participants:id,nom,prenom,telephone');

        $donnees = $request->validate([
            'contenu' => 'required_without:media|nullable|string|max:2000',
            'media' => 'required_without:contenu|nullable|string',
        ]);

        $mediaUrl = null;

        if (! empty($donnees['media'])) {
            try {
                $mediaUrl = $this->media->enregistrerImage($donnees['media'], 'messages');
            } catch (RuntimeException $e) {
                return response()->json([
                    'message' => $e->getMessage(),
                    'errors' => ['media' => [$e->getMessage()]],
                ], 422);
            }
        }

        $message = DB::transaction(function () use ($conversation, $request, $donnees, $mediaUrl) {
            $message = Message::create([
                'conversation_id' => $conversation->id,
                'expediteur_id' => $request->user()->id,
                'contenu' => $donnees['contenu'] ?? null,
                'media_url' => $mediaUrl,
                'type' => $mediaUrl ? 'image' : 'texte',
            ]);

            // Sert au tri de la liste des conversations.
            $conversation->update(['dernier_message_at' => $message->created_at]);

            // L'expéditeur a forcément lu son propre message.
            $conversation->participants()->updateExistingPivot(
                $request->user()->id,
                ['dernier_message_lu_id' => $message->id],
            );

            return $message;
        });

        $message->load('expediteur:id,nom,prenom,avatar_url');

        // Diffusé en temps réel aux autres participants (§7.2 : moins de
        // deux secondes d'affichage).
        MessageEnvoye::dispatch($message);

        // Et notifié à ceux qui n'ont pas l'application ouverte. Un message
        // n'est pas « important » au sens du repli SMS : il n'appelle pas
        // d'action immédiate, et le coût du SMS ne se justifie pas.
        $expediteurId = $request->user()->id;

        /** @var User $participant */
        foreach ($conversation->participants as $participant) {
            if ($participant->id === $expediteurId) {
                continue;
            }

            $this->notifications->notifier(
                $participant,
                trim(($request->user()->prenom ?? '').' '.$request->user()->nom),
                Str::limit($message->contenu ?? 'Photo', 80),
                type: 'message',
                donnees: ['conversation_id' => $conversation->id],
            );
        }

        return response()->json([
            'message' => 'Message envoyé.',
            'donnees' => new MessageResource($message),
        ], 201);
    }

    /**
     * Marque la conversation comme lue jusqu'à son dernier message.
     *
     * On borne au dernier message existant plutôt qu'à l'instant courant :
     * un message arrivé entre-temps ne doit pas être marqué lu sans avoir
     * jamais été affiché.
     */
    public function marquerLu(Request $request, Conversation $conversation): JsonResponse
    {
        $this->authorize('view', $conversation);

        $dernierId = $conversation->messages()->max('id');

        if ($dernierId) {
            $conversation->participants()->updateExistingPivot(
                $request->user()->id,
                ['dernier_message_lu_id' => $dernierId],
            );
        }

        return response()->json(['message' => 'Conversation marquée comme lue.']);
    }

    /** Ouvre ou récupère le fil de support Maboko. */
    public function support(Request $request): JsonResponse
    {
        $moi = $request->user();

        $conversation = Conversation::where('type', Conversation::TYPE_SUPPORT)
            ->whereHas('participants', fn ($r) => $r->where('users.id', $moi->id))
            ->first();

        if (! $conversation) {
            $conversation = Conversation::create(['type' => Conversation::TYPE_SUPPORT]);

            // Le support est rattaché à l'équipe d'administration ; s'il n'y a
            // pas encore d'administrateur, le fil reste ouvert côté client seul.
            $participants = [$moi->id];
            if ($admin = User::where('role', User::ROLE_ADMIN)->value('id')) {
                $participants[] = $admin;
            }

            $conversation->participants()->attach($participants);
        }

        return response()->json([
            'conversation' => new ConversationResource(
                $conversation->load('participants:id,nom,prenom,avatar_url,role'),
            ),
        ]);
    }
}
