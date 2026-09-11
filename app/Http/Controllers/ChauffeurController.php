<?php

namespace App\Http\Controllers;

use App\Models\Chauffeur;
use App\Models\Course;
use App\Models\PositionChauffeur;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

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
        ]);

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
