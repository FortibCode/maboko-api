<?php

namespace App\Policies;

use App\Models\Course;
use App\Models\User;

/**
 * Qui peut agir sur une course.
 *
 * Une course concerne deux personnes : le client qui l'a commandée et le
 * chauffeur qui l'a acceptée. Tant qu'elle cherche preneur, tout chauffeur
 * disponible peut la voir passer — mais personne d'autre.
 */
class CoursePolicy
{
    public function view(User $user, Course $course): bool
    {
        return $course->utilisateur_id === $user->id
            || $this->estLeChauffeur($user, $course)
            || $user->estAdmin();
    }

    /** Seul le chauffeur attribué fait avancer la course. */
    public function conduire(User $user, Course $course): bool
    {
        return $this->estLeChauffeur($user, $course);
    }

    /**
     * L'annulation est ouverte aux deux parties tant que le client n'est pas
     * à bord : après la prise en charge, la course va à son terme.
     */
    public function annuler(User $user, Course $course): bool
    {
        if ($course->estCloturee() || $course->statut === Course::STATUT_PRISE_EN_CHARGE) {
            return false;
        }

        return $course->utilisateur_id === $user->id || $this->estLeChauffeur($user, $course);
    }

    private function estLeChauffeur(User $user, Course $course): bool
    {
        return $course->chauffeur !== null
            && $course->chauffeur->utilisateur_id === $user->id;
    }
}
