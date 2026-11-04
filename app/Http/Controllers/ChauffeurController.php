<?php

namespace App\Http\Controllers;

use App\Models\Chauffeur;
use App\Models\Course;
use App\Models\PositionChauffeur;
use App\Services\MediaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use RuntimeException;

class ChauffeurController extends Controller
{
    public function __construct(private MediaService $media) {}

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
            'estClimatise' => (bool) $chauffeur->est_climatise,
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
            'coursesAujourdhui' => (int) (clone $terminees)->whereDate('terminee_at', now()->toDateString())->count(),
            'semaine' => $semaine->values(),
            'totalSemaine' => (float) $semaine->sum('total'),
            'total' => (float) (clone $terminees)->sum('tarif_final'),
            'nbCoursesTerminees' => $chauffeur->nb_courses_terminees,
        ]);
    }

    public function enregistrerFiche(Request $request): JsonResponse
    {
        $utilisateur = $request->user();

        if (! $utilisateur->estChauffeur()) {
            return response()->json([
                'message' => 'Seul un compte chauffeur peut déposer une fiche véhicule.',
            ], 403);
        }

        $existante = $this->fiche($request);

        $donnees = $request->validate([
            'type_vehicule' => ['required', 'string', Rule::in(['taxi', 'voiture', 'utilitaire', 'camion', 'bus', 'moto'])],
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
            'photo_recto' => ['sometimes', 'string'],
            'photo_verso' => ['sometimes', 'string'],
        ]);

        if (! empty($donnees['photo_recto'])) {
            try {
                $donnees['photo_recto'] = $this->media->enregistrerImage($donnees['photo_recto'], 'identites_chauffeurs');
            } catch (RuntimeException $e) {
                return response()->json(['message' => 'Erreur photo recto : '.$e->getMessage()], 422);
            }
        }
        if (! empty($donnees['photo_verso'])) {
            try {
                $donnees['photo_verso'] = $this->media->enregistrerImage($donnees['photo_verso'], 'identites_chauffeurs');
            } catch (RuntimeException $e) {
                return response()->json(['message' => 'Erreur photo verso : '.$e->getMessage()], 422);
            }
        }

        $donnees['statut_validation'] = Chauffeur::VALIDATION_EN_ATTENTE;
        $donnees['valide_at'] = null;

        if ($existante) {
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
            'message' => 'Fiche enregistrée. Elle sera examinée par l\'équipe Maboko.',
            'ficheManquante' => false,
            'id' => $chauffeur->id,
            'typeVehicule' => $chauffeur->type_vehicule,
            'estClimatise' => (bool) $chauffeur->est_climatise,
            'vehicule' => $chauffeur->vehicule_modele,
            'plaque' => $chauffeur->plaque_immatriculation,
            'permisCategories' => $chauffeur->permis_categories,
            'enLigne' => false,
            'disponible' => (bool) $chauffeur->disponibilite,
            'statutValidation' => $chauffeur->statut_validation,
            'noteMoyenne' => (float) $chauffeur->note_moyenne,
            'nbCoursesTerminees' => $chauffeur->nb_courses_terminees,
        ], $existante ? 200 : 201);
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