<?php

namespace App\Policies;

use App\Models\Artisan;
use App\Models\User;

class ArtisanPolicy
{
    /** Une fiche artisan est publique : c'est sa raison d'etre. */
    public function view(?User $user, Artisan $artisan): bool
    {
        return true;
    }

    public function update(User $user, Artisan $artisan): bool
    {
        return $artisan->utilisateur_id === $user->id || $user->estAdmin();
    }

    /** La validation d'un profil releve de l'administration (§4.4). */
    public function valider(User $user): bool
    {
        return $user->estAdmin();
    }
}
