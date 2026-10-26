<?php

namespace App\Services;

use App\Models\Appareil;
use App\Models\Artisan;
use App\Models\Chauffeur;
use App\Models\Message;
use App\Models\Post;
use App\Models\User;
use App\Models\VerificationIdentite;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Suppression du compte d'un utilisateur.
 *
 * Deux exigences s'opposent : le droit à l'effacement des données
 * personnelles, et l'obligation de conserver les pièces comptables. La
 * réponse retenue est l'anonymisation : l'identité disparaît, les
 * transactions restent rattachées à un compte anonyme, et la comptabilité
 * demeure vérifiable.
 *
 * Ce qui est réellement supprimé : pièces d'identité, publications, contenu
 * des messages, appareils, jetons de session.
 */
class SuppressionCompte
{
    public function __construct(private JournalAdministration $journal) {}

    public function supprimer(User $utilisateur): void
    {
        // Les fichiers vivent hors base : ils doivent partir avant la
        // transaction, sinon un échec laisserait des pièces orphelines.
        $this->supprimerPiecesIdentite($utilisateur);

        DB::transaction(function () use ($utilisateur) {
            Post::where('artisan_id', $utilisateur->id)->delete();
            Appareil::where('user_id', $utilisateur->id)->delete();
            VerificationIdentite::where('user_id', $utilisateur->id)->delete();

            // Le message reste dans le fil pour que la conversation garde son
            // sens, mais son contenu disparaît.
            Message::where('expediteur_id', $utilisateur->id)->update([
                'contenu' => 'Message supprimé',
                'media_url' => null,
            ]);

            // La fiche professionnelle n'a plus de titulaire.
            Artisan::where('utilisateur_id', $utilisateur->id)
                ->update(['statut_validation' => 'rejete', 'bio' => null]);
            Chauffeur::where('utilisateur_id', $utilisateur->id)
                ->update(['statut_validation' => 'rejete', 'en_ligne' => false, 'disponibilite' => false]);

            $utilisateur->tokens()->delete();

            $utilisateur->update([
                'nom' => 'Compte supprimé',
                'prenom' => null,
                // Valeurs uniques : les colonnes le sont, et un second compte
                // supprimé ne doit pas entrer en collision avec le premier.
                'email' => 'supprime-'.Str::uuid().'@maboko.invalid',
                'telephone' => '+00000'.substr((string) $utilisateur->id, -7),
                'avatar_url' => null,
                'ville' => null,
                'quartier' => null,
                'statut' => User::STATUT_SUSPENDU,
                'password' => Str::random(64),
            ]);
        });

        $this->journal->enregistrer('compte.supprime', $utilisateur);
    }

    private function supprimerPiecesIdentite(User $utilisateur): void
    {
        $verifications = VerificationIdentite::where('user_id', $utilisateur->id)->get();

        foreach ($verifications as $verification) {
            foreach ([$verification->chemin_recto, $verification->chemin_verso, $verification->chemin_selfie] as $chemin) {
                if ($chemin && Storage::disk(MediaService::disquePrive())->exists($chemin)) {
                    Storage::disk(MediaService::disquePrive())->delete($chemin);
                }
            }
        }
    }
}
