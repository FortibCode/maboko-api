<?php

namespace App\Http\Controllers;

use App\Models\Chauffeur;
use App\Models\Course;
use App\Models\PositionChauffeur;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Espace du chauffeur (§5.3) : fiche, disponibilité, position et revenus.
 */
class ChauffeurController extends Controller
{
    /**
     * Création de la fiche véhicule du chauffeur connecté (§5.3.1).
     *
     * Cette route manquait : le mobile l'appelait, mais aucune route
     * n'était déclarée côté serveur. Le compte restait sans fiche et
     * tout l'espace chauffeur répondait 404.
     */
    public function store(Request $request): JsonResponse
    {
        $donnees = $request->validate([
            'type_vehicule' => 'required|string|in:moto,voiture,utilitaire,camion,bus,taxi',
            'vehicule_modele' => 'required|string|max:100',
            'plaque_immatriculation' => 'required|string|max:20',
            'permis_conduire' => 'required|string|max:50',
            'permis_categories' => 'nullable|string|max:50',
        ]);

        // Le permis est unique par personne, mais un chauffeur peut
        // corriger sa propre fiche sans être bloqué par sa propre entrée.
        $existant = Chauffeur::where('permis_conduire', $donnees['permis_conduire'])
            ->where('utilisateur_id', '!=', $request->user()->id)
            ->exists();

        if ($existant) {
            return response()->json([
                'message' => 'The permis conduire has already been taken.',
                'errors' => [
                    'permis_conduire' => ['Ce numéro de permis est déjà utilisé.'],
                ],
            ], 422);
        }

        $chauffeur = Chauffeur::updateOrCreate(
            ['utilisateur_id' => $request->user()->id],
            [
                ...$donnees,
                'statut_validation' => 'en_attente',
            ],
        );

        return response()->json([
            'id' => $chauffeur->id,
            'typeVehicule' => $chauffeur->type_vehicule,
            'vehicule' => $chauffeur->vehicule_modele,
            'plaque' => $chauffeur->plaque_immatriculation,
            'permisConduire' => $chauffeur->permis_conduire,
            'permisCategories' => $chauffeur->permis_categories,
            'enLigne' => (bool) $chauffeur->en_ligne,
            'disponible' => (bool) $chauffeur->disponibilite,
            'statutValidation' => $chauffeur->statut_validation,
            'noteMoyenne' => (float) $chauffeur->note_moyenne,
            'nbCoursesTerminees' => $chauffeur->nb_courses_terminees,
        ], 201);
    }

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

    private function fiche(Request $request): ?Chauffeur
    {
        return Chauffeur::where('utilisateur_id', $request->user()->id)->first();
    }

    private function aUneCourseEnCours(Chauffeur $chauffeur): bool
    {
        return Course::where('chauffeur_id', $chauffeur->id)->actives()->exists();
    }
}