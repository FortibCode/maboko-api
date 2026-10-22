<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Artisan;
use App\Models\Badge;
use App\Models\Chauffeur;
use App\Models\User;
use App\Services\JournalAdministration;
use App\Services\MoteurBadges;
use App\Services\ServiceNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Gestion des artisans et des chauffeurs (§5.4.3).
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

    /**
     * Annuaire des artisans, filtrable.
     *
     * CORRECTION MAJEURE : on part de la table `users` (rôle artisan) et non
     * de la table `artisans`. Ainsi, les comptes dont la fiche n'a jamais été
     * créée (bug historique côté mobile) restent visibles dans l'admin avec
     * `ficheComplete: false` et `statutValidation: 'sans_fiche'`.
     *
     * Filtres acceptés :
     *  - statut=en_attente|valide|rejete|sans_fiche
     *  - q=nom|prenom|email|telephone|specialite
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
                // `id` = id de la fiche artisan (null si pas de fiche).
                // Le front admin doit gérer ce cas (badge "Fiche incomplète").
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
    public function chauffeurs(Request $request): JsonResponse
    {
        $requete = Chauffeur::query()
            ->with('utilisateur:id,nom,prenom,email,telephone,statut,derniere_connexion_at,role')
            ->when($request->filled('statut'), fn ($r) => $r->where('statut_validation', $request->string('statut')))
            ->latest();

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
            $this->notifications->notifier(
                $fiche->utilisateur,
                $donnees['decision'] === 'valide' ? 'Votre profil est validé' : 'Votre profil a été refusé',
                $donnees['decision'] === 'valide'
                    ? 'Vous apparaissez désormais dans les recherches Maboko.'
                    : ($donnees['motif'] ?? 'Contactez le support pour en savoir plus.'),
                type: 'validation_profil',
                important: true,
            );
        }

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