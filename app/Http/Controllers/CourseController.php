<?php

namespace App\Http\Controllers;

use App\Events\CourseMiseAJour;
use App\Http\Requests\EstimerCourseRequest;
use App\Http\Requests\ReserverCourseRequest;
use App\Http\Resources\CourseResource;
use App\Models\Chauffeur;
use App\Models\Course;
use App\Models\RefusCourse;
use App\Services\AppariementCourse;
use App\Services\ServiceNotification;
use App\Services\TarificationCourse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Allô Chauffeur, côté client (§5.1.8) et côté chauffeur (§5.3).
 */
class CourseController extends Controller
{
    public function __construct(
        private TarificationCourse $tarification,
        private AppariementCourse $appariement,
        private ServiceNotification $notifications,
    ) {}

    /**
     * Estimation du tarif avant réservation.
     *
     * Le client voit le prix avant de s'engager : c'est ce qui remplace la
     * négociation au bord de la route.
     */
    public function estimer(EstimerCourseRequest $request): JsonResponse
    {
        $donnees = $request->validated();

        $estimation = $this->tarification->estimer(
            (float) $donnees['depart_latitude'],
            (float) $donnees['depart_longitude'],
            (float) $donnees['arrivee_latitude'],
            (float) $donnees['arrivee_longitude'],
            $donnees['type_vehicule'],
        );

        return response()->json($estimation);
    }

    /** Historique des courses du compte connecté. */
    public function index(Request $request): AnonymousResourceCollection
    {
        $utilisateur = $request->user();

        $requete = Course::query()
            ->with(['chauffeur.utilisateur:id,nom,prenom,avatar_url,telephone', 'client:id,nom,prenom,avatar_url,role'])
            ->latest();

        if ($utilisateur->estChauffeur()) {
            $requete->whereHas('chauffeur', fn ($r) => $r->where('utilisateur_id', $utilisateur->id));
        } elseif (! $utilisateur->estAdmin()) {
            $requete->where('utilisateur_id', $utilisateur->id);
        }

        return CourseResource::collection($requete->cursorPaginate(20));
    }

    public function show(Request $request, Course $course): CourseResource
    {
        $this->authorize('view', $course);

        $course->load(['chauffeur.utilisateur', 'chauffeur.dernierePosition', 'client']);

        return new CourseResource($course);
    }

    /**
     * Réserve une course et la propose aux chauffeurs disponibles.
     */
    public function store(ReserverCourseRequest $request): JsonResponse
    {
        $utilisateur = $request->user();

        // Une seule course à la fois : deux réservations simultanées
        // mobiliseraient deux chauffeurs pour un seul passager.
        $enCours = Course::where('utilisateur_id', $utilisateur->id)
            ->whereIn('statut', [
                Course::STATUT_RECHERCHE,
                Course::STATUT_ACCEPTEE,
                Course::STATUT_EN_ROUTE,
                Course::STATUT_PRISE_EN_CHARGE,
            ])
            ->exists();

        if ($enCours) {
            return response()->json([
                'message' => 'Une course est déjà en cours. Terminez-la ou annulez-la avant d’en réserver une autre.',
            ], 422);
        }

        $donnees = $request->validated();

        $estimation = $this->tarification->estimer(
            (float) $donnees['depart_latitude'],
            (float) $donnees['depart_longitude'],
            (float) $donnees['arrivee_latitude'],
            (float) $donnees['arrivee_longitude'],
            $donnees['type_vehicule'],
        );

        $course = Course::create([
            ...$donnees,
            'utilisateur_id' => $utilisateur->id,
            'statut' => Course::STATUT_RECHERCHE,
            'distance_km' => $estimation['distanceKm'],
            'duree_estimee_min' => $estimation['dureeMin'],
            'tarif_estime' => $estimation['tarif'],
            // Colonne historique, conservée le temps de la reprise de données.
            'prix' => $estimation['tarif'],
        ]);

        $candidats = $this->proposerAuxChauffeurs($course);

        return response()->json([
            'message' => $candidats > 0
                ? 'Recherche d’un chauffeur à proximité…'
                : 'Aucun chauffeur disponible pour le moment. Réessayez dans quelques minutes.',
            'chauffeursContactes' => $candidats,
            'course' => new CourseResource($course),
        ], 201);
    }

    /**
     * Courses proposées au chauffeur connecté (§5.3.1).
     *
     * Ne remonte que celles qui cherchent encore preneur, dans son rayon et
     * pour son type de véhicule.
     */
    public function propositions(Request $request): AnonymousResourceCollection
    {
        $chauffeur = $this->ficheChauffeur($request);

        if (! $chauffeur || ! $chauffeur->en_ligne) {
            return CourseResource::collection(collect());
        }

        $courses = Course::query()
            ->where('statut', Course::STATUT_RECHERCHE)
            ->where('type_vehicule', $chauffeur->type_vehicule)
            ->where('created_at', '>=', now()->subMinutes(AppariementCourse::EXPIRATION_MINUTES))
            // Une course refusee ne doit plus revenir a chaque relecture.
            ->whereNotIn('id', RefusCourse::where('chauffeur_id', $chauffeur->id)->select('course_id'))
            ->with('client:id,nom,prenom,avatar_url,role')
            ->latest()
            ->limit(10)
            ->get();

        // « La distance jusqu'au point de prise en charge » (§5.3.1) : ce
        // n'est pas la longueur du trajet, qui elle est deja transmise. Sans
        // elle, le chauffeur ne pouvait pas juger si la course valait le
        // deplacement.
        $position = $chauffeur->dernierePosition;

        if ($position) {
            $courses->each(function (Course $course) use ($position) {
                $course->distance_prise_en_charge = $this->distanceKm(
                    (float) $position->latitude,
                    (float) $position->longitude,
                    (float) $course->depart_latitude,
                    (float) $course->depart_longitude,
                );
            });
        }

        return CourseResource::collection($courses);
    }

    /**
     * Le chauffeur refuse la course (§5.3.1).
     *
     * Elle disparait de ses propositions sans etre annulee : elle reste
     * offerte aux autres chauffeurs jusqu'a expiration.
     */
    public function refuser(Request $request, Course $course): JsonResponse
    {
        $chauffeur = $this->ficheChauffeur($request);

        if (! $chauffeur) {
            return response()->json(['message' => 'Aucune fiche chauffeur rattachée à ce compte.'], 403);
        }

        if ($course->statut !== Course::STATUT_RECHERCHE) {
            return response()->json(['message' => 'Cette course n’est plus proposée.'], 409);
        }

        RefusCourse::firstOrCreate([
            'course_id' => $course->id,
            'chauffeur_id' => $chauffeur->id,
        ]);

        return response()->json(['message' => 'Course refusée.']);
    }

    /** Le chauffeur accepte la course (§5.3.1). */
    public function accepter(Request $request, Course $course): JsonResponse
    {
        $chauffeur = $this->ficheChauffeur($request);

        if (! $chauffeur) {
            return response()->json(['message' => 'Aucune fiche chauffeur rattachée à ce compte.'], 403);
        }

        if ($course->type_vehicule !== $chauffeur->type_vehicule) {
            return response()->json(['message' => 'Cette course demande un autre type de véhicule.'], 422);
        }

        if (! $this->appariement->attribuer($course, $chauffeur)) {
            // Cas courant en heure de pointe, pas une erreur.
            return response()->json(['message' => 'Cette course vient d’être prise par un autre chauffeur.'], 409);
        }

        $course->refresh();

        $this->notifierClient($course, 'Chauffeur trouvé', 'Votre chauffeur arrive.', important: true);

        return $this->reponse($course, 'Course acceptée.');
    }

    /** Le chauffeur roule vers le point de prise en charge. */
    public function demarrer(Request $request, Course $course): JsonResponse
    {
        $this->authorize('conduire', $course);

        if ($course->statut !== Course::STATUT_ACCEPTEE) {
            return response()->json(['message' => 'Cette course n’est pas au bon stade.'], 422);
        }

        $course->update(['statut' => Course::STATUT_EN_ROUTE]);

        return $this->reponse($course, 'En route vers le client.');
    }

    /** Le client est à bord (§5.3.3). */
    public function prendreEnCharge(Request $request, Course $course): JsonResponse
    {
        $this->authorize('conduire', $course);

        if (! in_array($course->statut, [Course::STATUT_ACCEPTEE, Course::STATUT_EN_ROUTE], true)) {
            return response()->json(['message' => 'Cette course n’est pas au bon stade.'], 422);
        }

        $course->update([
            'statut' => Course::STATUT_PRISE_EN_CHARGE,
            'prise_en_charge_at' => now(),
        ]);

        return $this->reponse($course, 'Client à bord.');
    }

    /** Dépose et fin de course (§5.3.3). */
    public function terminer(Request $request, Course $course): JsonResponse
    {
        $this->authorize('conduire', $course);

        if ($course->statut !== Course::STATUT_PRISE_EN_CHARGE) {
            return response()->json(['message' => 'Le client n’est pas encore à bord.'], 422);
        }

        $donnees = $request->validate([
            'tarif_final' => 'sometimes|nullable|numeric|min:0|max:1000000',
        ]);

        $course->update([
            'statut' => Course::STATUT_TERMINEE,
            'tarif_final' => $donnees['tarif_final'] ?? $course->tarif_estime,
            'terminee_at' => now(),
        ]);

        $this->appariement->libererChauffeur($course);
        $course->chauffeur?->increment('nb_courses_terminees');

        $this->notifierClient($course, 'Course terminée', 'Merci d’avoir voyagé avec Maboko.');

        return $this->reponse($course, 'Course terminée.');
    }

    public function annuler(Request $request, Course $course): JsonResponse
    {
        $this->authorize('annuler', $course);

        $donnees = $request->validate([
            'motif' => 'sometimes|nullable|string|max:300',
        ]);

        $parLeClient = $course->utilisateur_id === $request->user()->id;

        $course->update([
            'statut' => Course::STATUT_ANNULEE,
            'annulee_par' => $parLeClient ? 'client' : 'chauffeur',
            'motif_annulation' => $donnees['motif'] ?? null,
        ]);

        $this->appariement->libererChauffeur($course);

        if (! $parLeClient) {
            $this->notifierClient(
                $course,
                'Course annulée',
                'Votre chauffeur a annulé la course. Vous pouvez en réserver une autre.',
                important: true,
            );
        }

        return $this->reponse($course, 'Course annulée.');
    }

    // ------------------------------------------------------------------

    /**
     * Prévient les chauffeurs proches. Le premier à accepter emporte la course.
     */
    private function proposerAuxChauffeurs(Course $course): int
    {
        $candidats = $this->appariement->candidats($course);

        foreach ($candidats as $chauffeur) {
            // Une course manquée est un revenu perdu : le repli SMS se justifie.
            $this->notifications->notifier(
                $chauffeur->utilisateur,
                'Nouvelle course disponible',
                $course->lieu_depart.' → '.$course->lieu_arrivee
                    .' · '.number_format((float) $course->tarif_estime, 0, ',', ' ').' FCFA',
                type: 'course_proposee',
                donnees: ['course_id' => $course->id],
                important: true,
            );
        }

        return $candidats->count();
    }

    private function notifierClient(Course $course, string $titre, string $corps, bool $important = false): void
    {
        // utilisateur_id est obligatoire et contraint : le client existe.
        $this->notifications->notifier(
            $course->client,
            $titre,
            $corps,
            type: 'course_'.$course->statut,
            donnees: ['course_id' => $course->id],
            important: $important,
        );
    }

    private function reponse(Course $course, string $message): JsonResponse
    {
        $course = $course->fresh(['chauffeur.utilisateur', 'chauffeur.dernierePosition', 'client']);

        // Le client suit l'évolution sur sa carte, sans interroger le serveur.
        CourseMiseAJour::dispatch($course);

        return response()->json([
            'message' => $message,
            'course' => new CourseResource($course),
        ]);
    }

    /**
     * Distance a vol d'oiseau entre deux points, en kilometres.
     *
     * Calculee en PHP : dix propositions ne justifient pas dix requetes
     * geographiques, et l'ordre de grandeur suffit a decider d'y aller.
     */
    private function distanceKm(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $rayonTerre = 6371.0;

        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);

        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;

        return round($rayonTerre * 2 * atan2(sqrt($a), sqrt(1 - $a)), 1);
    }

    private function ficheChauffeur(Request $request): ?Chauffeur
    {
        return Chauffeur::where('utilisateur_id', $request->user()->id)->first();
    }
}
