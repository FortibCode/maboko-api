<?php

namespace App\Http\Controllers;

use App\Models\Chauffeur;
use App\Models\Course;
use App\Models\PositionChauffeur;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Espace du chauffeur (§5.3.4) : disponibilité, position et revenus.
 */
class ChauffeurController extends Controller
{
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
            'enLigne' => (bool) $chauffeur->en_ligne,
            'disponible' => (bool) $chauffeur->disponibilite,
            'statutValidation' => $chauffeur->statut_validation,
            'noteMoyenne' => (float) $chauffeur->note_moyenne,
            'nbCoursesTerminees' => $chauffeur->nb_courses_terminees,
        ]);
    }

    /**
     * Bascule entre en ligne et hors ligne (§5.3.4).
     *
     * Passer hors ligne pendant une course en cours est refusé : le client
     * attend, et le chauffeur disparaîtrait de son écran de suivi.
     */
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
            // Repasser en ligne rend de nouveau joignable, sauf course en cours.
            'disponibilite' => $donnees['en_ligne'] && ! $this->aUneCourseEnCours($chauffeur),
        ]);

        return response()->json([
            'message' => $donnees['en_ligne'] ? 'Vous êtes en ligne.' : 'Vous êtes hors ligne.',
            'enLigne' => (bool) $chauffeur->fresh()->en_ligne,
        ]);
    }

    /**
     * Position transmise par l'application chauffeur.
     *
     * Elle sert à deux choses : proposer les courses au plus proche, et
     * permettre au client de suivre l'arrivée de son véhicule.
     */
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

    /**
     * Revenus du jour et des sept derniers jours (§5.3.4).
     */
    public function revenus(Request $request): JsonResponse
    {
        $chauffeur = $this->fiche($request);

        if (! $chauffeur) {
            return response()->json(['message' => 'Aucune fiche chauffeur rattachée à ce compte.'], 403);
        }

        $terminees = Course::where('chauffeur_id', $chauffeur->id)
            ->where('statut', Course::STATUT_TERMINEE);

        // Un seul passage en base pour les sept jours, plutôt qu'une requête
        // par jour : l'écran s'ouvre sur une connexion lente.
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
