<?php

use App\Models\Conversation;
use App\Models\Course;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, int $id) {
    return (int) $user->id === $id;
});

/*
| Canal privé d'une conversation : l'abonnement n'est accordé qu'à ses
| participants. Sans ce contrôle, connaître un identifiant suffirait à
| écouter les échanges d'autrui en temps réel.
*/
Broadcast::channel('conversation.{conversationId}', function ($user, int $conversationId) {
    return Conversation::whereKey($conversationId)
        ->whereHas('participants', fn ($requete) => $requete->where('users.id', $user->id))
        ->exists();
});

/*
| Canal privé d'une course : le client suit l'arrivée de son chauffeur, et le
| chauffeur les changements de statut. Personne d'autre n'y a accès.
*/
Broadcast::channel('course.{courseId}', function ($user, int $courseId) {
    $course = Course::with('chauffeur')->find($courseId);

    if (! $course) {
        return false;
    }

    return $course->utilisateur_id === $user->id
        || $course->chauffeur?->utilisateur_id === $user->id;
});
