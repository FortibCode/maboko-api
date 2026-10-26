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
<<<<<<< HEAD
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
=======
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
>>>>>>> 191747fdc8be36e8985fed8248eddadb6cd3fa61

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

<<<<<<< HEAD
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
=======
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
>>>>>>> 191747fdc8be36e8985fed8248eddadb6cd3fa61
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
            ->latest()
            ->paginate(25);

<<<<<<< HEAD
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
=======
        return ArtisanAdminResource::collection($artisans);
>>>>>>> 191747fdc8be36e8985fed8248eddadb6cd3fa61
    }

    /** Annuaire des chauffeurs. */
    public function chauffeurs(Request $request): AnonymousResourceCollection
    {
        $chauffeurs = Chauffeur::query()
            ->with('utilisateur:id,nom,prenom,email,telephone,statut,derniere_connexion_at,role')
            ->when($request->filled('statut'), fn ($r) => $r->where('statut_validation', $request->string('statut')))
            ->latest()
            ->paginate(25);

<<<<<<< HEAD
        return response()->json($requete->paginate(25)->through(fn (Chauffeur $c) => [
            'id' => $c->id,
            'nomComplet' => trim(($c->utilisateur->prenom ?? '').' '.$c->utilisateur->nom),
            'email' => $c->utilisateur->email,
            'telephone' => $c->utilisateur->telephone,
            'typeVehicule' => $c->type_vehicule,
            'vehicule' => $c->vehicule_modele,
            'plaque' => $c->plaque_immatriculation,
            'permis' => $c->permis_conduire,
            'permisCategories' => $c->permis_categories,
            'statutValidation' => $c->statut_validation,
            'compteSuspendu' => $c->utilisateur->statut === User::STATUT_SUSPENDU,
            'enLigne' => (bool) $c->en_ligne,
            'nbCoursesTerminees' => $c->nb_courses_terminees,
            'inscritLe' => $c->created_at?->toIso8601String(),
        ]));
=======
        return ChauffeurAdminResource::collection($chauffeurs);
>>>>>>> 191747fdc8be36e8985fed8248eddadb6cd3fa61
    }

    /** Validation d'une inscription artisan ou chauffeur. */
    public function valider(Request $request, string $type, int $id): JsonResponse
    {
        $donnees = $request->validate([
            'decision' => 'required|in:valide,rejete',
            'motif' => 'sometimes|nullable|string|max:500',
        ]);

<<<<<<< HEAD
        $fiche = $type === 'artisans' ? Artisan::find($id) : Chauffeur::find($id);

        if (! $fiche) {
            return response()->json([
                'message' => $type === 'artisans'
                    ? "Cet artisan n'a pas encore de fiche. Demandez-lui de la compléter depuis l'application avant de valider."
                    : 'Fiche introuvable.',
            ], 404);
        }
=======
        /** @var Artisan|Chauffeur $fiche */
        $fiche = $type === 'artisans'
            ? Artisan::findOrFail($id)
            : Chauffeur::findOrFail($id);
>>>>>>> 191747fdc8be36e8985fed8248eddadb6cd3fa61

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

<<<<<<< HEAD
        // ⚠️ CORRECTIF : isole l'envoi de notification dans un try/catch.
        // Si l'envoi de SMS échoue (ex: pas configuré sur Vercel), 
        // la validation est quand même sauvegardée et l'admin ne voit pas d'erreur.
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
                // On log l'erreur pour toi dans storage/logs/laravel.log
                Log::error("Échec de la notification (SMS/Push) lors de la validation : " . $e->getMessage());
                // On ne bloque pas la réponse JSON
            }
        }
=======
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
>>>>>>> 191747fdc8be36e8985fed8248eddadb6cd3fa61

        return response()->json([
            'message' => $donnees['decision'] === 'valide' ? 'Profil validé.' : 'Profil refusé.',
            'statutValidation' => $fiche->fresh()->statut_validation,
        ]);
    }

    /**
     * Suspension ou réactivation d'un compte (§5.4.3).
     */
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
     * Suppression définitive d'un compte (artisan, chauffeur, client).
     */
    public function supprimerUtilisateur(Request $request, User $utilisateur): JsonResponse
    {
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
                $utilisateur->tokens()->delete();

                if ($artisan = $utilisateur->artisan) {
                    $artisan->metiers()->detach();
                    $artisan->badges()->detach();
                    DB::table('demandes')->where('artisan_id', $artisan->id)->delete();
                    DB::table('avis')->where('artisan_id', $artisan->id)->delete();
                    DB::table('abonnements')->where('artisan_id', $artisan->id)->delete();
                    $artisan->delete();
                }

                if ($utilisateur->chauffeur) {
                    $utilisateur->chauffeur->delete();
                }

                DB::table('demandes')->where('client_id', $utilisateur->id)->delete();
                DB::table('avis')->where('auteur_id', $utilisateur->id)->delete();
                DB::table('favoris')->where('utilisateur_id', $utilisateur->id)->delete();
                DB::table('follows')
                    ->where('follower_id', $utilisateur->id)
                    ->orWhere('artisan_id', $utilisateur->id)
                    ->delete();
                DB::table('transactions')->where('utilisateur_id', $utilisateur->id)->delete();

                $utilisateur->delete();
            });
        } catch (\Throwable $e) {
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