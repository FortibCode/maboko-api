<?php

namespace App\Http\Controllers;

use App\Models\Chauffeur;
use App\Models\Course;
use App\Models\PositionChauffeur;
use App\Models\User;
use App\Models\VerificationIdentite;
use App\Services\MediaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use RuntimeException;

/**
 * Espace du chauffeur (§5.3) : fiche, disponibilité, position et revenus.
 */
class ChauffeurController extends Controller
{
    public function __construct(private MediaService $media) {}

    /** Fiche et état du chauffeur connecté. */
    public function moi(Request $request): JsonResponse
    {
        $chauffeur = $this->fiche($request);

        if (! $chauffeur) {
            return response()->json([
                'ficheManquante' => true,
                'message' => 'Complétez votre fiche chauffeur pour recevoir des courses.',
            ], 404);
        }

        return response()->json([
            'ficheManquante' => false,
            'id' => $chauffeur->id,
            'typeVehicule' => $chauffeur->type_vehicule,
            'vehicule' => $chauffeur->vehicule_modele,
            'plaque' => $chauffeur->plaque_immatriculation,
            'permisConduire' => $chauffeur->permis_conduire,
            'permisCategories' => $chauffeur->permis_categories,
            'climatise' => (bool) $chauffeur->est_climatise,
            'enLigne' => (bool) $chauffeur->en_ligne,
            'disponible' => (bool) $chauffeur->disponibilite,
            'statutValidation' => $chauffeur->statut_validation,
            'noteMoyenne' => (float) $chauffeur->note_moyenne,
            'nbCoursesTerminees' => $chauffeur->nb_courses_terminees,
        ]);
    }

    public function basculerDisponibilite(Request $request): JsonResponse
    {
        $chauffeur = $this->fiche($request);

        if (! $chauffeur) {
            return response()->json(['message' => 'Aucune fiche chauffeur rattachée à ce compte.'], 403);
        }

        $donnees = $request->validate(['en_ligne' => 'required|boolean']);

        if (! $donnees['en_ligne'] && $this->aUneCourseEnCours($chauffeur)) {
            return response()->json([
                'message' => 'Terminez ou annulez votre course avant de passer hors ligne.',
            ], 422);
        }

        $chauffeur->update([
            'en_ligne' => $donnees['en_ligne'],
            'disponibilite' => $donnees['en_ligne'] && ! $this->aUneCourseEnCours($chauffeur),
        ]);

        return response()->json([
            'message' => $donnees['en_ligne'] ? 'Vous êtes en ligne.' : 'Vous êtes hors ligne.',
            'enLigne' => (bool) $chauffeur->fresh()->en_ligne,
        ]);
    }

    public function transmettrePosition(Request $request): JsonResponse
    {
        $chauffeur = $this->fiche($request);

        if (! $chauffeur) {
            return response()->json(['message' => 'Aucune fiche chauffeur rattachée à ce compte.'], 403);
        }

        $donnees = $request->validate([
            'latitude' => 'required|numeric|between:-90,90',
            'longitude' => 'required|numeric|between:-180,180',
            'cap' => 'sometimes|nullable|integer|between:0,359',
            'vitesse_kmh' => 'sometimes|nullable|numeric|min:0|max:300',
        ]);

        PositionChauffeur::create([
            ...$donnees,
            'chauffeur_id' => $chauffeur->id,
            'releve_at' => now(),
        ]);

        return response()->json(['message' => 'Position enregistrée.'], 201);
    }

    public function revenus(Request $request): JsonResponse
    {
        $chauffeur = $this->fiche($request);

        if (! $chauffeur) {
            return response()->json(['message' => 'Aucune fiche chauffeur rattachée à ce compte.'], 403);
        }

        $terminees = Course::where('chauffeur_id', $chauffeur->id)
            ->where('statut', Course::STATUT_TERMINEE);

        $parJour = (clone $terminees)
            ->where('terminee_at', '>=', now()->subDays(6)->startOfDay())
            ->selectRaw('date(terminee_at) as jour, count(*) as courses, coalesce(sum(tarif_final), 0) as total')
            ->groupBy('jour')
            ->pluck('total', 'jour');

        $nombreParJour = (clone $terminees)
            ->where('terminee_at', '>=', now()->subDays(6)->startOfDay())
            ->selectRaw('date(terminee_at) as jour, count(*) as courses')
            ->groupBy('jour')
            ->pluck('courses', 'jour');

        $semaine = collect(range(6, 0))->map(function (int $recul) use ($parJour, $nombreParJour) {
            $jour = now()->subDays($recul)->toDateString();

            return [
                'jour' => $jour,
                'total' => (float) ($parJour[$jour] ?? 0),
                'courses' => (int) ($nombreParJour[$jour] ?? 0),
            ];
        });

        return response()->json([
            'aujourdhui' => (float) (clone $terminees)->whereDate('terminee_at', now()->toDateString())->sum('tarif_final'),
            'coursesAujourdhui' => (clone $terminees)->whereDate('terminee_at', now()->toDateString())->count(),
            'semaine' => $semaine->values(),
            'totalSemaine' => (float) $semaine->sum('total'),
            'total' => (float) (clone $terminees)->sum('tarif_final'),
            'nbCoursesTerminees' => $chauffeur->nb_courses_terminees,
        ]);
    }

    /**
     * Depot de la fiche vehicule par le chauffeur lui-meme (§5.3.1).
     *
     * Jusqu'ici seul le seeder creait des lignes « chauffeurs » : un compte
     * inscrit depuis l'application n'avait aucun moyen d'obtenir sa fiche, et
     * restait bloque sur un ecran l'invitant a contacter l'equipe.
     *
     * La fiche part en attente : c'est l'ecran d'administration existant qui
     * la valide ou la refuse. Une modification apres validation repasse en
     * attente, car le vehicule approuve n'est plus le meme.
     */
    public function enregistrerFiche(Request $request): JsonResponse
    {
        $utilisateur = $request->user();

        if (! $utilisateur->estChauffeur()) {
            return response()->json([
                'message' => 'Seul un compte chauffeur peut deposer une fiche vehicule.',
            ], 403);
        }

        $existante = $this->fiche($request);

        $donnees = $request->validate([
            'type_vehicule' => ['required', Rule::in([Chauffeur::VEHICULE_MOTO, Chauffeur::VEHICULE_VOITURE])],
            'vehicule_modele' => ['required', 'string', 'max:120'],
            'plaque_immatriculation' => [
                'required', 'string', 'max:20',
                Rule::unique('chauffeurs', 'plaque_immatriculation')->ignore($existante?->id),
            ],
            'permis_conduire' => [
                'required', 'string', 'max:50',
                Rule::unique('chauffeurs', 'permis_conduire')->ignore($existante?->id),
            ],
            'permis_categories' => ['nullable', 'string', 'max:50'],
            'est_climatise' => ['sometimes', 'boolean'],
            'photo_recto' => ['required_with:photo_verso', 'string'],
            'photo_verso' => ['required_with:photo_recto', 'string'],
        ]);

        // Déposées avant la fiche : une photo illisible ne doit pas laisser une
        // fiche enregistrée sans sa pièce d'identité.
        if (isset($donnees['photo_recto'])) {
            try {
                $this->deposerPieceIdentite($utilisateur, $donnees['photo_recto'], $donnees['photo_verso']);
            } catch (RuntimeException $e) {
                return response()->json([
                    'message' => $e->getMessage(),
                    'errors' => ['photo_recto' => [$e->getMessage()]],
                ], 422);
            }
        }

        unset($donnees['photo_recto'], $donnees['photo_verso']);

        $donnees['statut_validation'] = Chauffeur::VALIDATION_EN_ATTENTE;
        $donnees['valide_at'] = null;

        if ($existante) {
            // Un vehicule non valide ne doit pas rester visible a l'appariement.
            $donnees['en_ligne'] = false;
            $existante->update($donnees);
            $chauffeur = $existante->fresh();
        } else {
            $chauffeur = Chauffeur::create($donnees + [
                'utilisateur_id' => $utilisateur->id,
                'disponibilite' => true,
                'en_ligne' => false,
            ]);
        }

        return response()->json([
            'message' => 'Fiche enregistree. Elle sera examinee par l\'equipe Maboko.',
            'ficheManquante' => false,
            'id' => $chauffeur->id,
            'typeVehicule' => $chauffeur->type_vehicule,
            'vehicule' => $chauffeur->vehicule_modele,
            'plaque' => $chauffeur->plaque_immatriculation,
            'permisCategories' => $chauffeur->permis_categories,
            'climatise' => (bool) $chauffeur->est_climatise,
            'enLigne' => false,
            'disponible' => (bool) $chauffeur->disponibilite,
            'statutValidation' => $chauffeur->statut_validation,
            'noteMoyenne' => (float) $chauffeur->note_moyenne,
            'nbCoursesTerminees' => $chauffeur->nb_courses_terminees,
        ], $existante ? 200 : 201);
    }

    /**
     * La pièce rejoint le circuit de vérification d'identité (§4.5) : stockée
     * chiffrée, et montrée à l'administration seule, par lien signé.
     *
     * Une pièce en cours d'examen ou déjà validée reste valable : renvoyer la
     * fiche pour changer de véhicule ne relance pas la vérification d'identité.
     */
    private function deposerPieceIdentite(User $utilisateur, string $recto, string $verso): void
    {
        $dejaDeposee = VerificationIdentite::where('user_id', $utilisateur->id)
            ->whereIn('statut', ['en_attente', 'valide'])
            ->exists();

        if ($dejaDeposee) {
            return;
        }

        VerificationIdentite::create([
            'user_id' => $utilisateur->id,
            'type_piece' => 'cni',
            'statut' => 'en_attente',
            'chemin_recto' => $this->media->enregistrerPiecePrivee($recto, 'identites'),
            'chemin_verso' => $this->media->enregistrerPiecePrivee($verso, 'identites'),
        ]);
    }

    private function fiche(Request $request): ?Chauffeur
    {
        return Chauffeur::where('utilisateur_id', $request->user()->id)->first();
    }

    private function aUneCourseEnCours(Chauffeur $chauffeur): bool
    {
        return Course::where('chauffeur_id', $chauffeur->id)->actives()->exists();
    }
}
