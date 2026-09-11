<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\ArtisanAdminResource;
use App\Http\Resources\Admin\ChauffeurAdminResource;
use App\Http\Resources\Admin\UserAdminResource;
use App\Models\Artisan;
use App\Models\Badge;
use App\Models\Chauffeur;
use App\Models\User;
use App\Services\JournalAdministration;
use App\Services\MoteurBadges;
use App\Services\ServiceNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Gestion des artisans, des chauffeurs et de l'ensemble des utilisateurs (§5.4.3).
 *
 * Annuaire complet, validation des inscriptions, activation et suspension.
 */
class ComptesController extends Controller
{
    public function __construct(
        private JournalAdministration $journal,
        private ServiceNotification $notifications,
        private MoteurBadges $badges,
    ) {}

    /** Annuaire global de tous les utilisateurs. */
    public function utilisateurs(Request $request): AnonymousResourceCollection
    {
        $utilisateurs = User::query()
            ->when($request->filled('role'), fn ($r) => $r->where('role', $request->string('role')))
            ->when($request->filled('statut'), fn ($r) => $r->where('statut', $request->string('statut')))
            ->when($request->filled('q'), function ($r) use ($request) {
                $terme = '%'.$request->string('q').'%';
                $r->where('nom', 'like', $terme)
                    ->orWhere('prenom', 'like', $terme)
                    ->orWhere('email', 'like', $terme)
                    ->orWhere('telephone', 'like', $terme);
            })
            ->latest()
            ->paginate(25);

        return UserAdminResource::collection($utilisateurs);
    }

    /** Annuaire des artisans, filtrable. */
    public function artisans(Request $request): AnonymousResourceCollection
    {
        $artisans = Artisan::query()
            ->with([
                'utilisateur:id,nom,prenom,email,telephone,statut,avatar_url,derniere_connexion_at,role',
                'metiers', 'badges', 'abonnementActif.plan',
            ])
            ->when($request->filled('statut'), fn ($r) => $r->where('statut_validation', $request->string('statut')))
            ->when($request->filled('q'), function ($r) use ($request) {
                $terme = '%'.$request->string('q').'%';
                $r->where('specialite', 'like', $terme)
                    ->orWhereHas('utilisateur', fn ($u) => $u->where('nom', 'like', $terme)->orWhere('email', 'like', $terme));
            })
            ->latest()
            ->paginate(25);

        return ArtisanAdminResource::collection($artisans);
    }

    /** Annuaire des chauffeurs. */
    public function chauffeurs(Request $request): AnonymousResourceCollection
    {
        $chauffeurs = Chauffeur::query()
            ->with('utilisateur:id,nom,prenom,email,telephone,statut,derniere_connexion_at,role')
            ->when($request->filled('statut'), fn ($r) => $r->where('statut_validation', $request->string('statut')))
            ->latest()
            ->paginate(25);

        return ChauffeurAdminResource::collection($chauffeurs);
    }

    /** Validation d'une inscription artisan ou chauffeur. */
    public function valider(Request $request, string $type, int $id): JsonResponse
    {
        $donnees = $request->validate([
            'decision' => 'required|in:valide,rejete',
            'motif' => 'sometimes|nullable|string|max:500',
        ]);

        /** @var Artisan|Chauffeur $fiche */
        $fiche = $type === 'artisans'
            ? Artisan::findOrFail($id)
            : Chauffeur::findOrFail($id);

        $avant = $fiche->statut_validation;

        $fiche->update([
            'statut_validation' => $donnees['decision'],
            'valide_at' => $donnees['decision'] === 'valide' ? now() : null,
        ]);

        $this->journal->enregistrer(
            "compte.{$type}.".$donnees['decision'],
            $fiche,
            ['statut_validation' => $avant],
            ['statut_validation' => $donnees['decision'], 'motif' => $donnees['motif'] ?? null],
        );

        // La clé étrangère est obligatoire et contrainte : l'utilisateur existe.
        $this->notifications->notifier(
            $fiche->utilisateur,
            $donnees['decision'] === 'valide' ? 'Votre profil est validé' : 'Votre profil a été refusé',
            $donnees['decision'] === 'valide'
                ? 'Vous apparaissez désormais dans les recherches Maboko.'
                : ($donnees['motif'] ?? 'Contactez le support pour en savoir plus.'),
            type: 'validation_profil',
            important: true,
        );

        return response()->json([
            'message' => $donnees['decision'] === 'valide' ? 'Profil validé.' : 'Profil refusé.',
            'statutValidation' => $fiche->fresh()->statut_validation,
        ]);
    }

    /**
     * Suspension ou réactivation d'un compte (§5.4.3).
     *
     * La suspension révoque les sessions ouvertes : sans cela, un compte
     * suspendu reste actif jusqu'à l'expiration de son jeton.
     */
    public function basculerSuspension(Request $request, User $utilisateur): JsonResponse
    {
        $donnees = $request->validate([
            'suspendre' => 'required|boolean',
            'motif' => 'sometimes|nullable|string|max:500',
        ]);

        if ($utilisateur->estAdmin()) {
            return response()->json(['message' => 'Un compte d’administration ne se suspend pas depuis cet écran.'], 422);
        }

        $avant = $utilisateur->statut;
        $nouveau = $donnees['suspendre'] ? User::STATUT_SUSPENDU : User::STATUT_ACTIF;

        $utilisateur->update(['statut' => $nouveau]);

        if ($donnees['suspendre']) {
            $utilisateur->tokens()->delete();
        }

        $this->journal->enregistrer(
            $donnees['suspendre'] ? 'compte.suspendu' : 'compte.reactive',
            $utilisateur,
            ['statut' => $avant],
            ['statut' => $nouveau, 'motif' => $donnees['motif'] ?? null],
        );

        return response()->json([
            'message' => $donnees['suspendre'] ? 'Compte suspendu.' : 'Compte réactivé.',
            'statut' => $nouveau,
        ]);
    }

    /** Attribution manuelle d'un badge (§4.5). */
    public function attribuerBadge(Request $request, Artisan $artisan): JsonResponse
    {
        $donnees = $request->validate([
            'badge' => 'required|string|exists:badges,slug',
            'retirer' => 'sometimes|boolean',
        ]);

        $badge = Badge::where('slug', $donnees['badge'])->firstOrFail();

        if ($request->boolean('retirer')) {
            $this->badges->retirer($artisan, $badge);
            $this->journal->enregistrer('badge.retire', $artisan, [], ['badge' => $badge->slug]);

            return response()->json(['message' => 'Badge retiré.']);
        }

        $accorde = $this->badges->attribuerManuellement($artisan, $badge, $request->user()->id);

        if ($accorde) {
            $this->journal->enregistrer('badge.attribue', $artisan, [], ['badge' => $badge->slug]);
        }

        return response()->json([
            'message' => $accorde ? 'Badge attribué.' : 'Cet artisan possède déjà ce badge.',
        ], $accorde ? 201 : 200);
    }
}
