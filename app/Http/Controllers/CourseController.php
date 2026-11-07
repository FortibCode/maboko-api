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

class CourseController extends Controller
{
    public function __construct(
        private TarificationCourse $tarification,
        private AppariementCourse $appariement,
        private ServiceNotification $notifications,
    ) {}

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

    public function index(Request $request): AnonymousResourceCollection
    {
        $utilisateur = $request->user();
        $requete = Course::query()
            ->with(['chauffeur.utilisateur:id,nom,prenom,avatar_url,telephone', 'chauffeurPropose.utilisateur:id,nom,prenom,avatar_url,telephone', 'client:id,nom,prenom,avatar_url,role'])
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
        $course->load(['chauffeur.utilisateur', 'chauffeur.dernierePosition', 'chauffeurPropose.utilisateur', 'chauffeurPropose.dernierePosition', 'client']);

        return new CourseResource($course);
    }

    public function store(ReserverCourseRequest $request): JsonResponse
    {
        $utilisateur = $request->user();

        $enCours = Course::where('utilisateur_id', $utilisateur->id)
            ->whereIn('statut', [
                Course::STATUT_RECHERCHE,
                Course::STATUT_NEGOCIATION,
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

        $prixClient = $donnees['prix_client'] ?? $estimation['tarif'];

        $course = Course::create([
            ...$donnees,
            'utilisateur_id' => $utilisateur->id,
            'statut' => Course::STATUT_RECHERCHE,
            'distance_km' => $estimation['distanceKm'],
            'duree_estimee_min' => $estimation['dureeMin'],
            'tarif_estime' => $estimation['tarif'],
            'prix' => $prixClient,
            'prix_client' => $prixClient,
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

    public function propositions(Request $request): AnonymousResourceCollection
    {
        $chauffeur = $this->ficheChauffeur($request);

        if (! $chauffeur || ! $chauffeur->en_ligne) {
            return CourseResource::collection(collect());
        }

        $courses = Course::query()
            ->where(function ($query) use ($chauffeur) {
                $query->where('statut', Course::STATUT_RECHERCHE)
                    ->orWhere(function ($q) use ($chauffeur) {
                        $q->where('statut', Course::STATUT_NEGOCIATION)
                            ->where('chauffeur_propose_id', $chauffeur->id);
                    });
            })
            ->when($chauffeur->type_vehicule, fn ($q) => $q->where('type_vehicule', $chauffeur->type_vehicule))
            ->where('created_at', '>=', now()->subMinutes(AppariementCourse::EXPIRATION_MINUTES))
            ->whereNotIn('id', RefusCourse::where('chauffeur_id', $chauffeur->id)->select('course_id'))
            ->with(['client:id,nom,prenom,avatar_url,role', 'chauffeurPropose.utilisateur'])
            ->latest()
            ->limit(20)
            ->get();

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

    public function accepter(Request $request, Course $course): JsonResponse
    {
        $chauffeur = $this->ficheChauffeur($request);
        if (! $chauffeur) {
            return response()->json(['message' => 'Aucune fiche chauffeur.'], 403);
        }

        if ($course->type_vehicule !== $chauffeur->type_vehicule) {
            return response()->json(['message' => 'Cette course demande un autre type de véhicule.'], 422);
        }

        if (! $this->appariement->attribuer($course, $chauffeur)) {
            return response()->json(['message' => 'Cette course vient d’être prise par un autre chauffeur.'], 409);
        }

        $course->refresh();
        $this->notifierClient($course, 'Chauffeur trouvé', 'Votre chauffeur arrive.', important: true);
        $this->notifierAutresChauffeursCoursePrise($course, $chauffeur->id);

        return $this->reponse($course, 'Course acceptée.');
    }

    public function refuser(Request $request, Course $course): JsonResponse
    {
        $chauffeur = $this->ficheChauffeur($request);
        if (! $chauffeur) {
            return response()->json(['message' => 'Aucune fiche chauffeur.'], 403);
        }

        if (! in_array($course->statut, [Course::STATUT_RECHERCHE, Course::STATUT_NEGOCIATION])) {
            return response()->json(['message' => 'Cette course n’est plus proposée.'], 409);
        }

        RefusCourse::firstOrCreate([
            'course_id' => $course->id,
            'chauffeur_id' => $chauffeur->id,
        ]);

        return response()->json(['message' => 'Course refusée.']);
    }

    /**
     * Le chauffeur propose un autre prix que celui du client (§5.1.9).
     */
    public function contreProposer(Request $request, Course $course): JsonResponse
    {
        $chauffeur = $this->ficheChauffeur($request);

        if (! $chauffeur) {
            return response()->json(['message' => 'Aucune fiche chauffeur rattachée à ce compte.'], 403);
        }

        if ($course->type_vehicule !== $chauffeur->type_vehicule) {
            return response()->json(['message' => 'Cette course demande un autre type de véhicule.'], 422);
        }

        $donnees = $request->validate([
            'prix_chauffeur' => 'required|numeric|min:100|max:1000000',
        ]);

        $reservee = Course::whereKey($course->id)
            ->where('statut', Course::STATUT_RECHERCHE)
            ->whereNull('chauffeur_id')
            ->update([
                'statut' => Course::STATUT_NEGOCIATION,
                'chauffeur_propose_id' => $chauffeur->id,
                'prix_chauffeur' => $donnees['prix_chauffeur'],
            ]);

        if ($reservee === 0) {
            return response()->json(['message' => 'Cette course n’est plus proposée.'], 409);
        }

        $course->refresh();

        $this->notifierClient(
            $course,
            'Un chauffeur vous propose un prix',
            number_format((float) $course->prix_chauffeur, 0, ',', ' ').' FCFA pour votre course. Acceptez ou refusez.',
            important: true,
        );

        // Les autres chauffeurs sont informés que la course a été prise
        $this->notifierAutresChauffeursCoursePrise($course, $chauffeur->id);

        return $this->reponse($course, 'Proposition envoyée au client.');
    }

    /**
     * Le client accepte le prix proposé par le chauffeur (§5.1.9).
     */
    public function accepterPrix(Request $request, Course $course): JsonResponse
    {
        $this->authorize('negocier', $course);

        $chauffeur = $course->chauffeurPropose;

        if ($course->statut !== Course::STATUT_NEGOCIATION || ! $chauffeur) {
            return response()->json(['message' => 'Aucune proposition en attente pour cette course.'], 409);
        }

        $occupe = ! $chauffeur->en_ligne
            || ! $chauffeur->disponibilite
            || Course::where('chauffeur_id', $chauffeur->id)->actives()->exists();

        if ($occupe) {
            $this->rouvrir($course, $chauffeur);

            return response()->json([
                'message' => 'Ce chauffeur n’est plus disponible. Nous cherchons un autre chauffeur.',
                'course' => new CourseResource($course->fresh(['client'])),
            ], 409);
        }

        $attribuee = Course::whereKey($course->id)
            ->where('statut', Course::STATUT_NEGOCIATION)
            ->where('chauffeur_propose_id', $chauffeur->id)
            ->update([
                'chauffeur_id' => $chauffeur->id,
                'statut' => Course::STATUT_ACCEPTEE,
                'acceptee_at' => now(),
            ]);

        if ($attribuee === 0) {
            return response()->json(['message' => 'Cette proposition n’est plus valable.'], 409);
        }

        $chauffeur->update(['disponibilite' => false]);

        $this->notifications->notifier(
            $chauffeur->utilisateur,
            'Prix accepté',
            'Le client a accepté votre prix. Rendez-vous au point de départ.',
            type: 'course_prix_accepte',
            donnees: ['course_id' => $course->id],
            important: true,
        );

        return $this->reponse($course, 'Prix accepté. Votre chauffeur arrive.');
    }

    /**
     * Le client refuse le prix du chauffeur.
     */
    public function refuserPrix(Request $request, Course $course): JsonResponse
    {
        $this->authorize('negocier', $course);

        $chauffeur = $course->chauffeurPropose;

        if ($course->statut !== Course::STATUT_NEGOCIATION || ! $chauffeur) {
            return response()->json(['message' => 'Aucune proposition en attente pour cette course.'], 409);
        }

        $this->rouvrir($course, $chauffeur);

        $this->notifications->notifier(
            $chauffeur->utilisateur,
            'Prix refusé',
            'Le client a refusé votre proposition.',
            type: 'course_prix_refuse',
            donnees: ['course_id' => $course->id],
        );

        // Reproposer la course aux autres chauffeurs
        $this->proposerAuxChauffeurs($course);

        return $this->reponse($course, 'Proposition refusée. Nous cherchons un autre chauffeur.');
    }

    public function demarrer(Request $request, Course $course): JsonResponse
    {
        $this->authorize('conduire', $course);
        if ($course->statut !== Course::STATUT_ACCEPTEE) {
            return response()->json(['message' => 'Cette course n’est pas au bon stade.'], 422);
        }
        $course->update(['statut' => Course::STATUT_EN_ROUTE]);

        return $this->reponse($course, 'En route vers le client.');
    }

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
            'tarif_final' => $donnees['tarif_final'] ?? $course->tarifConvenu(),
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
            $this->notifierClient($course, 'Course annulée', 'Votre chauffeur a annulé la course.', important: true);
        }

        return $this->reponse($course, 'Course annulée.');
    }

    // ------------------------------------------------------------------

    private function proposerAuxChauffeurs(Course $course): int
    {
        $candidats = $this->appariement->candidats($course);
        foreach ($candidats as $chauffeur) {
            if ($chauffeur->utilisateur) {
                $this->notifications->notifier(
                    $chauffeur->utilisateur,
                    'Nouvelle course disponible',
                    $course->lieu_depart.' → '.$course->lieu_arrivee
                        .' · '.number_format($course->tarifConvenu(), 0, ',', ' ').' FCFA',
                    type: 'course_proposee',
                    donnees: ['course_id' => $course->id],
                    important: true,
                );
            }
        }

        return $candidats->count();
    }

    private function notifierAutresChauffeursCoursePrise(Course $course, int $chauffeurExcluId): void
    {
        $autresChauffeurs = Chauffeur::query()
            ->where('en_ligne', true)
            ->where('statut_validation', Chauffeur::VALIDATION_VALIDE)
            ->when($course->type_vehicule, fn ($q) => $q->where('type_vehicule', $course->type_vehicule))
            ->where('id', '!=', $chauffeurExcluId)
            ->with('utilisateur')
            ->get();

        foreach ($autresChauffeurs as $autre) {
            if ($autre->utilisateur) {
                $this->notifications->notifier(
                    $autre->utilisateur,
                    'Course prise',
                    'La course vers '.$course->lieu_arrivee.' a été prise par un autre chauffeur.',
                    type: 'course_prise',
                    donnees: ['course_id' => $course->id],
                );
            }
        }
    }

    private function rouvrir(Course $course, Chauffeur $chauffeur): void
    {
        $course->update([
            'statut' => Course::STATUT_RECHERCHE,
            'chauffeur_propose_id' => null,
            'prix_chauffeur' => null,
        ]);

        RefusCourse::firstOrCreate([
            'course_id' => $course->id,
            'chauffeur_id' => $chauffeur->id,
        ]);
    }

    private function notifierClient(Course $course, string $titre, string $corps, bool $important = false): void
    {
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
        $course = $course->fresh(['chauffeur.utilisateur', 'chauffeur.dernierePosition', 'chauffeurPropose.utilisateur', 'chauffeurPropose.dernierePosition', 'client']);
        CourseMiseAJour::dispatch($course);

        return response()->json([
            'message' => $message,
            'course' => new CourseResource($course),
        ]);
    }

    private function distanceKm(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $rayonTerre = 6371.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;

        return round($rayonTerre * 2 * atan2(sqrt($a), sqrt(1 - $a)), 1);
    }

    private function ficheChauffeur(Request $request): ?Chauffeur
    {
        return Chauffeur::where('utilisateur_id', $request->user()->id)->first();
    }
}
