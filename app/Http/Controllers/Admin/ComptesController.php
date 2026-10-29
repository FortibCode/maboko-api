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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Gestion des artisans, des chauffeurs et de l'ensemble des utilisateurs (§5.4.3).
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
                $r->where(function($query) use ($terme) {
                    $query->where('nom', 'like', $terme)
                        ->orWhere('prenom', 'like', $terme)
                        ->orWhere('email', 'like', $terme)
                        ->orWhere('telephone', 'like', $terme);
                });
            })
            ->latest()
            ->paginate(25);

        return UserAdminResource::collection($utilisateurs);
    }

    /**
     * Annuaire des artisans, filtrable.
     */
    public function artisans(Request $request): JsonResponse
    {
        $requete = User::query()
            ->where('role', User::ROLE_ARTISAN)
            ->with([
                'artisan' => fn ($q) => $q->with(['metiers', 'badges', 'abonnementActif.plan']),
            ])
            ->when($request->filled('statut'), function ($r) use ($request) {
                $statut = (string) $request->string('statut');

                if ($statut === 'sans_fiche') {
                    $r->doesntHave('artisan');
                } else {
                    $r->whereHas('artisan', fn ($q) => $q->where('statut_validation', $statut));
                }
            })
            ->when($request->filled('q'), function ($r) use ($request) {
                $terme = '%'.$request->string('q').'%';
                $r->where(function ($q) use ($terme) {
                    $q->where('nom', 'like', $terme)
                        ->orWhere('prenom', 'like', $terme)
                        ->orWhere('email', 'like', $terme)
                        ->orWhere('telephone', 'like', $terme)
                        ->orWhereHas('artisan', fn ($a) => $a->where('specialite', 'like', $terme));
                });
            })
            ->latest();

        return response()->json($requete->paginate(25)->through(function (User $u) {
            $fiche = $u->artisan;

            return [
                'id' => $fiche?->id,
                'utilisateurId' => $u->id,
                'nomComplet' => trim(($u->prenom ?? '').' '.$u->nom),
                'email' => $u->email,
                'telephone' => $u->telephone,
                'specialite' => $fiche?->specialite,
                'metiers' => $fiche?->metiers->pluck('nom') ?? [],
                'badges' => $fiche?->badges->pluck('nom') ?? [],
                'statutValidation' => $fiche?->statut_validation ?? 'sans_fiche',
                'ficheComplete' => $fiche !== null,
                'compteSuspendu' => $u->statut === User::STATUT_SUSPENDU,
                'noteMoyenne' => (float) ($fiche?->note_moyenne ?? 0),
                'nbMissionsTerminees' => $fiche?->nb_missions_terminees ?? 0,
                'plan' => $fiche?->abonnementActif?->plan->nom ?? 'Gratuit',
                'derniereConnexion' => $u->derniere_connexion_at?->toIso8601String(),
                'inscritLe' => $u->created_at?->toIso8601String(),
            ];
        }));
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

        $fiche = $type === 'artisans' ? Artisan::find($id) : Chauffeur::find($id);

        if (! $fiche) {
            return response()->json([
                'message' => $type === 'artisans'
                    ? "Cet artisan n'a pas encore de fiche. Demandez-lui de la compléter depuis l'application avant de valider."
                    : 'Fiche introuvable.',
            ], 404);
        }

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

        if ($fiche->utilisateur) {
            try {
                $this->notifications->notifier(
                    $fiche->utilisateur,
                    $donnees['decision'] === 'valide' ? 'Votre profil est validé' : 'Votre profil a été refusé',
                    $donnees['decision'] === 'valide'
                        ? 'Vous apparaissez désormais dans les recherches Maboko.'
                        : ($donnees['motif'] ?? 'Contactez le support pour en savoir plus.'),
                    type: 'validation_profil',
                    important: true,
                );
            } catch (\Throwable $e) {
                Log::error("Échec de la notification (SMS/Push) lors de la validation : " . $e->getMessage());
            }
        }

        return response()->json([
            'message' => $donnees['decision'] === 'valide' ? 'Profil validé.' : 'Profil refusé.',
            'statutValidation' => $fiche->fresh()->statut_validation,
        ]);
    }

    /** Suspension ou réactivation d'un compte. */
    public function basculerSuspension(Request $request, User $utilisateur): JsonResponse
    {
        $donnees = $request->validate([
            'suspendre' => 'required|boolean',
            'motif' => 'sometimes|nullable|string|max:500',
        ]);

        if ($utilisateur->estAdmin()) {
            return response()->json(['message' => "Un compte d'administration ne se suspend pas depuis cet écran."], 422);
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

    /**
     * Suppression définitive d'un compte.
     * Utilise forceDelete() pour vraiment libérer le téléphone et l'email.
     */
    public function supprimerUtilisateur(Request $request, $utilisateurId): JsonResponse
    {
        // User n'utilise pas SoftDeletes : un compte supprimé n'est plus en
        // base, et find() suffit à le constater.
        $utilisateur = User::find($utilisateurId);

        if (! $utilisateur) {
            return response()->json([
                'message' => 'Cet utilisateur a déjà été supprimé.',
            ], 200);
        }

        if ($utilisateur->estAdmin()) {
            return response()->json([
                'message' => "Les comptes d'administration ne peuvent pas être supprimés depuis cet écran.",
            ], 403);
        }

        if ($request->user()->id === $utilisateur->id) {
            return response()->json([
                'message' => 'Vous ne pouvez pas supprimer votre propre compte.',
            ], 403);
        }

        $identite = trim(($utilisateur->prenom ?? '').' '.$utilisateur->nom);

        try {
            DB::transaction(function () use ($utilisateur) {
                // 1. Tokens
                $utilisateur->tokens()->delete();

                // 2. Fiche artisan + dépendances
                if ($artisan = $utilisateur->artisan) {
                    $artisan->metiers()->detach();
                    $artisan->badges()->detach();
                    $artisan->demandes()->delete();
                    $artisan->avis()->delete();
                    $artisan->abonnements()->delete();
                    
                    // Tables qui pourraient ne pas exister dans certaines bases
                    // stories.artisan_id et posts.artisan_id désignent le compte
                    // (users.id), pas la fiche artisan.
                    if (Schema::hasTable('stories')) {
                        DB::table('stories')->where('artisan_id', $utilisateur->id)->delete();
                    }
                    if (Schema::hasTable('posts')) {
                        DB::table('posts')->where('artisan_id', $utilisateur->id)->delete();
                    }
                    
                    $artisan->delete();
                }

                // 3. Fiche chauffeur
                if ($utilisateur->chauffeur) {
                    $utilisateur->chauffeur()->delete();
                }

                // 4. Données du client
                $utilisateur->demandes()->delete();
                $utilisateur->avisDeposes()->delete();
                $utilisateur->favoris()->delete();
                $utilisateur->transactions()->delete();
                
                if (method_exists($utilisateur, 'courses')) {
                    $utilisateur->courses()->delete();
                }
                if (method_exists($utilisateur, 'otpCodes')) {
                    $utilisateur->otpCodes()->delete();
                }

                // 5. Follows (pivot)
                if (Schema::hasTable('follows')) {
                    DB::table('follows')
                        ->where('follower_id', $utilisateur->id)
                        ->orWhere('artisan_id', $utilisateur->id)
                        ->delete();
                }

                // 6. Messages (expéditeur)
                if (Schema::hasTable('messages')) {
                    DB::table('messages')->where('expediteur_id', $utilisateur->id)->delete();
                }

                // 7. Conversations (pivot participant)
                if (Schema::hasTable('conversation_participants')) {
                    DB::table('conversation_participants')->where('user_id', $utilisateur->id)->delete();
                    
                    if (Schema::hasTable('conversations')) {
                        DB::table('conversations')
                            ->whereNotIn('id', DB::table('conversation_participants')->select('conversation_id'))
                            ->delete();
                    }
                }

                // 8. Likes et commentaires
                if (Schema::hasTable('likes')) {
                    DB::table('likes')->where('user_id', $utilisateur->id)->delete();
                }
                if (Schema::hasTable('commentaires')) {
                    DB::table('commentaires')->where('user_id', $utilisateur->id)->delete();
                }

                // 9. Notifications
                if (Schema::hasTable('notifications')) {
                    DB::table('notifications')
                        ->where('notifiable_id', $utilisateur->id)
                        ->where('notifiable_type', User::class)
                        ->delete();
                }

                // 10. Vérification identité
                if (method_exists($utilisateur, 'verificationIdentite')) {
                    if ($verif = $utilisateur->verificationIdentite) {
                        $verif->delete();
                    }
                }

                // 11. SUPPRESSION DÉFINITIVE (forceDelete libère le téléphone et l'email)
                $utilisateur->forceDelete();
            });
        } catch (\Throwable $e) {
            Log::error("Erreur de suppression pour l'utilisateur {$utilisateurId} : " . $e->getMessage());
            return response()->json([
                'message' => 'Suppression impossible : '.$e->getMessage(),
            ], 500);
        }

        $this->journal->enregistrer(
            'compte.supprime',
            null,
            [],
            ['identite' => $identite, 'role' => $utilisateur->role],
        );

        return response()->json([
            'message' => 'Compte supprimé définitivement.',
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