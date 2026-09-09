<?php

namespace App\Events;

use App\Http\Resources\MessageResource;
use App\Models\Message;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Diffusé à chaque nouveau message d'une conversation.
 *
 * Le paragraphe 7.2 fixe un délai d'affichage inférieur à deux secondes :
 * la diffusion WebSocket évite d'attendre le prochain rafraîchissement.
 */
class MessageEnvoye implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public Message $message) {}

    /**
     * Canal privé propre à la conversation : seuls ses participants peuvent
     * s'y abonner (voir routes/channels.php).
     */
    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('conversation.'.$this->message->conversation_id);
    }

    public function broadcastAs(): string
    {
        return 'message.envoye';
    }

    /**
     * La charge diffusée reprend exactement la forme renvoyée par l'API,
     * pour que le client n'ait qu'un seul format à traiter.
     *
     * « deMoi » est absent ici : l'abonné n'est pas connu au moment de la
     * diffusion, l'application le déduit de l'identifiant de l'expéditeur.
     */
    public function broadcastWith(): array
    {
        $this->message->loadMissing('expediteur:id,nom,prenom,avatar_url');

        return [
            'message' => (new MessageResource($this->message))->resolve(),
        ];
    }
}
