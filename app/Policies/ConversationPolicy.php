<?php

namespace App\Policies;

use App\Models\Conversation;
use App\Models\User;

/**
 * Une conversation n'est visible que de ses participants.
 * L'administration y accède pour traiter les litiges (§3.4).
 */
class ConversationPolicy
{
    public function view(User $user, Conversation $conversation): bool
    {
        return $this->participe($user, $conversation) || $user->estAdmin();
    }

    /** Seul un participant écrit dans une conversation. */
    public function ecrire(User $user, Conversation $conversation): bool
    {
        return $this->participe($user, $conversation);
    }

    private function participe(User $user, Conversation $conversation): bool
    {
        return $conversation->participants()
            ->where('users.id', $user->id)
            ->exists();
    }
}
