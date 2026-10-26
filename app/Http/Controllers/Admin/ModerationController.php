<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\LitigeResource;
use App\Http\Resources\Admin\SignalementResource;
use App\Http\Resources\Admin\VerificationResource;
use App\Models\Artisan;
use App\Models\Avis;
use App\Models\Badge;
use App\Models\Commentaire;
use App\Models\Litige;
use App\Models\Post;
use App\Models\Report;
use App\Models\VerificationIdentite;
use App\Services\ClassementArtisan;
use App\Services\JournalAdministration;
use App\Services\MoteurBadges;
use App\Services\ServiceNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Modération du contenu (§5.4.2), vérification d'identité et litiges (§3.4).
 */
class ModerationController extends Controller
{
    public function __construct(
        private JournalAdministration $journal,
        private ServiceNotification $notifications,
        private MoteurBadges $badges,
        private ClassementArtisan $classement,
    ) {}

    // ------------------------------------------------------------------
    // Signalements (§5.4.2)
    // ------------------------------------------------------------------

    public function signalements(Request $request): AnonymousResourceCollection
    {
        $signalements = Report::query()
            ->with('reporter:id,nom,prenom,role')
            ->where('statut', $request->string('statut', 'en_attente'))
            ->latest()
            ->paginate(25);

        return SignalementResource::collection($signalements);
    }

    /** Suppression ou classement sans suite d'un signalement. */
    public function traiterSignalement(Request $request, Report $signalement): JsonResponse
    {
        $donnees = $request->validate([
            'decision' => 'required|in:supprimer,ignorer',
            'commentaire' => 'sometimes|nullable|string|max:500',
        ]);

        if ($donnees['decision'] === 'supprimer' && $signalement->target_type === 'post') {
            Post::where('id', $signalement->target_id)->delete();
        }

        $signalement->update([
            'statut' => $donnees['decision'] === 'supprimer' ? 'traite' : 'ignore',
            'traite_par' => $request->user()->id,
            'traite_at' => now(),
            'decision' => $donnees['commentaire'] ?? null,
        ]);

        $this->journal->enregistrer(
            'signalement.'.$donnees['decision'],
            $signalement,
            [],
            ['cible' => $signalement->target_type.'#'.$signalement->target_id],
        );

        return response()->json([
            'message' => $donnees['decision'] === 'supprimer'
                ? 'Contenu supprimé.'
                : 'Signalement classé sans suite.',
        ]);
    }

    /** Masquage d'un avis abusif : la note de l'artisan est recalculée. */
    public function masquerAvis(Request $request, Avis $avis): JsonResponse
    {
        $avis->update(['statut_moderation' => 'masque']);

        if ($artisan = $avis->artisan) {
            $this->classement->recalculer($artisan);
        }

        $this->journal->enregistrer('avis.masque', $avis);

        return response()->json(['message' => 'Avis masqué. La note de l’artisan a été recalculée.']);
    }

    public function supprimerCommentaire(Commentaire $commentaire): JsonResponse
    {
        $commentaire->update(['statut_moderation' => 'masque']);

        $this->journal->enregistrer('commentaire.masque', $commentaire);

        return response()->json(['message' => 'Commentaire masqué.']);
    }

    // ------------------------------------------------------------------
    // Vérification d'identité (§4.5)
    // ------------------------------------------------------------------

    public function verifications(Request $request): AnonymousResourceCollection
    {
        $verifications = VerificationIdentite::query()
            ->with('user:id,nom,prenom,email,telephone,role')
            ->where('statut', $request->string('statut', 'en_attente'))
            ->latest()
            ->paginate(25);

        return VerificationResource::collection($verifications);
    }

    public function traiterVerification(Request $request, VerificationIdentite $verification): JsonResponse
    {
        $donnees = $request->validate([
            'decision' => 'required|in:valide,rejete',
            'motif' => 'required_if:decision,rejete|nullable|string|max:500',
        ]);

        $verification->update([
            'statut' => $donnees['decision'],
            'verifie_par' => $request->user()->id,
            'verifie_at' => now(),
            'motif_rejet' => $donnees['decision'] === 'rejete' ? $donnees['motif'] : null,
        ]);

        // Une identité validée débloque le badge « Profil vérifié ».
        if ($donnees['decision'] === 'valide') {
            $artisan = Artisan::where('utilisateur_id', $verification->user_id)->first();
            $badge = Badge::where('slug', 'profil-verifie')->first();

            if ($artisan && $badge) {
                $this->badges->attribuerManuellement($artisan, $badge, $request->user()->id);
            }
        }

        $this->journal->enregistrer('identite.'.$donnees['decision'], $verification);

        // La clé étrangère est obligatoire : l'utilisateur existe.
        $this->notifications->notifier(
            $verification->user,
            $donnees['decision'] === 'valide' ? 'Identité vérifiée' : 'Vérification refusée',
            $donnees['decision'] === 'valide'
                ? 'Le badge « Profil vérifié » apparaît sur votre fiche.'
                : ($donnees['motif'] ?? 'Déposez de nouvelles pièces.'),
            type: 'verification_identite',
            important: true,
        );

        return response()->json([
            'message' => $donnees['decision'] === 'valide' ? 'Identité validée.' : 'Vérification refusée.',
        ]);
    }

    // ------------------------------------------------------------------
    // Litiges (§3.4)
    // ------------------------------------------------------------------

    public function litiges(Request $request): AnonymousResourceCollection
    {
        $litiges = Litige::query()
            ->with(['auteur:id,nom,prenom,email,role', 'responsable:id,nom,prenom,role'])
            ->when(
                $request->filled('statut'),
                fn ($r) => $r->where('statut', $request->string('statut')),
                fn ($r) => $r->whereIn('statut', ['ouvert', 'en_cours']),
            )
            ->latest()
            ->paginate(25);

        return LitigeResource::collection($litiges);
    }

    public function traiterLitige(Request $request, Litige $litige): JsonResponse
    {
        $donnees = $request->validate([
            'statut' => 'required|in:en_cours,resolu,clos',
            'resolution' => 'required_if:statut,resolu|nullable|string|max:1000',
        ]);

        $litige->update([
            'statut' => $donnees['statut'],
            'assigne_a' => $request->user()->id,
            'resolution' => $donnees['resolution'] ?? $litige->resolution,
            'resolu_at' => in_array($donnees['statut'], ['resolu', 'clos'], true) ? now() : null,
        ]);

        $this->journal->enregistrer('litige.'.$donnees['statut'], $litige);

        return response()->json(['message' => 'Litige mis à jour.']);
    }
}
