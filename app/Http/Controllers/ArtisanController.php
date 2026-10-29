<?php

namespace App\Http\Controllers;

use App\Http\Requests\MettreAJourArtisanRequest;
use App\Http\Requests\RechercheArtisanRequest;
use App\Http\Resources\ArtisanResource;
use App\Http\Resources\AvisResource;
use App\Models\Artisan;
use App\Models\Metier;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\ResourceCollection;

class ArtisanController extends Controller
{
    /**
     * Recherche d'artisans (§4.1) : par métier, par zone géographique
     * et par niveau de compétence.
     *
     * Seuls les artisans validés apparaissent côté client. La validation
     * est automatique dès que l'artisan complète sa fiche (voir store()),
     * mais peut être révoquée manuellement par l'admin.
     */
    public function index(RechercheArtisanRequest $request): ResourceCollection
    {
        $filtres = $request->validated();

        $requete = Artisan::query()
            ->valides()
            ->with(['utilisateur:id,nom,prenom,avatar_url,ville,quartier,role', 'metiers', 'badges', 'abonnementActif.plan']);

        if (isset($filtres['metier'])) {
            $requete->parMetier($filtres['metier']);
        }

        if (isset($filtres['badge'])) {
            $requete->whereHas('badges', fn ($r) => $r->where('slug', $filtres['badge']));
        }

        if (isset($filtres['note_min'])) {
            $requete->where('note_moyenne', '>=', $filtres['note_min']);
        }

        if (isset($filtres['ville'])) {
            $requete->whereHas('utilisateur', fn ($r) => $r->where('ville', $filtres['ville']));
        }

        // Recherche libre sur le nom de l'artisan et sa spécialité.
        if (isset($filtres['q'])) {
            $terme = '%'.$filtres['q'].'%';
            $requete->where(function ($r) use ($terme) {
                $r->where('specialite', 'like', $terme)
                    ->orWhereHas('utilisateur', fn ($u) => $u->where('nom', 'like', $terme)->orWhere('prenom', 'like', $terme));
            });
        }

        $geolocalisee = isset($filtres['latitude'], $filtres['longitude']);

        if ($geolocalisee) {
            $requete->aProximite(
                (float) $filtres['latitude'],
                (float) $filtres['longitude'],
                (int) ($filtres['rayon_km'] ?? 15),
            );
        }

        // Le tri par distance n'a de sens qu'avec des coordonnées ; il est
        // déjà appliqué par le scope, on n'y touche pas.
        $tri = $filtres['tri'] ?? ($geolocalisee ? 'distance' : 'pertinence');

        match ($tri) {
            'note' => $requete->orderByDesc('note_moyenne')->orderByDesc('nb_avis'),
            'missions' => $requete->orderByDesc('nb_missions_terminees'),
            'distance' => null,
            default => $requete->orderByDesc('score_classement'),
        };

        return ArtisanResource::collection(
            $requete->paginate($filtres['par_page'] ?? 20)->withQueryString(),
        );
    }

    /**
     * Création OU mise à jour de la fiche de l'artisan connecté (§5.2.1).
     *
     * Idempotent : utilise updateOrCreate sur utilisateur_id. Si la fiche
     * a déjà été créée automatiquement à l'inscription (OtpController),
     * elle est simplement mise à jour avec les vraies données du formulaire.
     *
     * ⚠️ AUTO-VALIDATION : dès que l'artisan soumet ses vraies données
     * (spécialité, adresse, coordonnées, métiers), la fiche passe
     * automatiquement à `statut_validation = 'valide'`. Elle devient donc
     * immédiatement visible dans la recherche client.
     */
    public function store(Request $request): JsonResponse|ArtisanResource
    {
        $utilisateur = $request->user();

        if ($utilisateur->role !== User::ROLE_ARTISAN) {
            return response()->json([
                'message' => 'Seuls les comptes artisans peuvent créer une fiche.',
            ], 403);
        }

        $donnees = $request->validate([
            'specialite' => 'required|string|max:255',
            'adresse' => 'required|string|max:255',
            'latitude' => 'required|numeric|between:-90,90',
            'longitude' => 'required|numeric|between:-180,180',
            'bio' => 'nullable|string|max:2000',
            'zone_intervention' => 'nullable|string|max:255',
            'rayon_km' => 'nullable|integer|min:1|max:100',
            'metiers' => 'nullable|array|max:5',
            'metiers.*' => 'string|exists:metiers,slug',
        ]);

        // ── AUTO-VALIDATION ────────────────────────────────────────────
        // La fiche complétée par l'artisan est directement valide. Elle
        // apparaît donc tout de suite dans la recherche client et peut
        // recevoir des devis. L'admin peut toujours revenir en arrière.
        $donnees['statut_validation'] = Artisan::VALIDATION_VALIDE;
        $donnees['valide_at'] = now();

        $artisan = Artisan::updateOrCreate(
            ['utilisateur_id' => $utilisateur->id],
            collect($donnees)->except('metiers')->all(),
        );

        if (isset($donnees['metiers'])) {
            $artisan->metiers()->sync(
                Metier::whereIn('slug', $donnees['metiers'])->pluck('id'),
            );
        }

        $statut = $artisan->wasRecentlyCreated ? 201 : 200;

        return (new ArtisanResource(
            $artisan->fresh(['utilisateur', 'metiers', 'badges', 'abonnementActif.plan']),
        ))->response()->setStatusCode($statut);
    }

    /**
     * Fiche complète consultée par un client avant la prise de contact (§5.1.6).
     */
    public function show(Artisan $artisan): ArtisanResource
    {
        $artisan->load([
            'utilisateur:id,nom,prenom,avatar_url,ville,quartier,role',
            'metiers',
            'badges',
            'abonnementActif.plan',
            'avis' => fn ($r) => $r->where('statut_moderation', 'publie')->with('auteur:id,nom,prenom,avatar_url')->latest()->limit(5),
        ]);

        return new ArtisanResource($artisan);
    }

    /** Avis d'un artisan, paginés. */
    public function avis(Artisan $artisan): AnonymousResourceCollection
    {
        $avis = $artisan->avis()
            ->where('statut_moderation', 'publie')
            ->with('auteur:id,nom,prenom,avatar_url')
            ->latest()
            ->paginate(20);

        return AvisResource::collection($avis);
    }

    /** Fiche de l'artisan connecté. */
    public function me(Request $request): JsonResponse|ArtisanResource
    {
        $artisan = Artisan::where('utilisateur_id', $request->user()->id)
            ->with(['utilisateur', 'metiers', 'badges', 'abonnementActif.plan'])
            ->first();

        if (! $artisan) {
            return response()->json([
                'message' => "Aucune fiche artisan n'est rattachée à ce compte.",
            ], 404);
        }

        return new ArtisanResource($artisan);
    }

    public function update(MettreAJourArtisanRequest $request, Artisan $artisan): ArtisanResource
    {
        $this->authorize('update', $artisan);

        $donnees = $request->validated();

        $artisan->update(collect($donnees)->except('metiers')->all());

        if (isset($donnees['metiers'])) {
            $artisan->metiers()->sync(
                Metier::whereIn('slug', $donnees['metiers'])->pluck('id'),
            );
        }

        return new ArtisanResource(
            $artisan->fresh(['utilisateur', 'metiers', 'badges', 'abonnementActif.plan']),
        );
    }
}
