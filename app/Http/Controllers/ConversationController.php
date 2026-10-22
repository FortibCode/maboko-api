    public function envoyer(Request $request, Conversation $conversation): JsonResponse
    {
        $this->authorize('ecrire', $conversation);

        $conversation->loadMissing('participants:id,nom,prenom,telephone');

        $donnees = $request->validate([
            'contenu' => 'required_without:media|nullable|string|max:2000',
            'media' => 'required_without:contenu|nullable|string',
            // Le client précise s'il envoie une photo ou un message vocal.
            // Sans ce champ, on retombe sur l'ancien comportement (image).
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

        MessageEnvoye::dispatch($message);

        $expediteurId = $request->user()->id;

        /** @var User $participant */
        foreach ($conversation->participants as $participant) {
            if ($participant->id === $expediteurId) {
                continue;
            }

            // Le résumé de notification s'adapte au type de message.
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