<?php

namespace App\Policies;

use App\Models\DemandeDevis;
use App\Models\User;

/**
 * Qui peut faire quoi sur une demande de devis.
 *
 * Une demande met en relation exactement deux personnes : le client qui
 * l'a emise et l'artisan qui la recoit. Personne d'autre n'y a acces,
 * l'administration exceptee.
 */
class DemandeDevisPolicy
{
    public function view(User $user, DemandeDevis $demande): bool
    {
        return $this->estClient($user, $demande)
            || $this->estArtisan($user, $demande)
            || $user->estAdmin();
    }

    /** Seul un client emet une demande de devis. */
    public function create(User $user): bool
    {
        return $user->role === User::ROLE_CLIENT;
    }

    /** L'artisan destinataire accepte ou refuse, tant que rien n'est engage. */
    public function repondre(User $user, DemandeDevis $demande): bool
    {
        return $this->estArtisan($user, $demande)
            && $demande->statut === DemandeDevis::STATUT_EN_ATTENTE;
    }

    /** Le chantier demarre une fois la demande acceptee. */
    public function demarrer(User $user, DemandeDevis $demande): bool
    {
        return $this->estArtisan($user, $demande)
            && $demande->statut === DemandeDevis::STATUT_ACCEPTEE;
    }

    /** Seul l'artisan cloture une intervention, et seulement si elle a commence. */
    public function terminer(User $user, DemandeDevis $demande): bool
    {
        return $this->estArtisan($user, $demande)
            && in_array($demande->statut, [DemandeDevis::STATUT_ACCEPTEE, DemandeDevis::STATUT_EN_COURS], true);
    }

    /** Le client peut se retirer tant que le travail n'est pas termine. */
    public function annuler(User $user, DemandeDevis $demande): bool
    {
        return $this->estClient($user, $demande) && ! $demande->estCloturee();
    }

    /** On ne note qu'une intervention reellement terminee, et une seule fois. */
    public function noter(User $user, DemandeDevis $demande): bool
    {
        return $this->estClient($user, $demande)
            && $demande->statut === DemandeDevis::STATUT_TERMINEE;
    }

    private function estClient(User $user, DemandeDevis $demande): bool
    {
        return $demande->client_id === $user->id;
    }

    private function estArtisan(User $user, DemandeDevis $demande): bool
    {
        return $demande->artisan?->utilisateur_id === $user->id;
    }
}
