<?php

namespace App\Http\Controllers;

use App\Http\Requests\CreerDemandeDevisRequest;
use App\Http\Resources\DemandeDevisResource;
use App\Models\Artisan;
use App\Models\DemandeDevis;
use App\Models\Metier;
use App\Services\ClassementArtisan;
use App\Services\MediaService;
use App\Services\MoteurBadges;
use App\Services\ServiceNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Demandes de devis : le coeur de la marketplace.
 *
 * Cote client, ecran « Demande de devis » (§5.1.7) ;
 * cote artisan, ecran « Missions reçues » (§5.2.2).
 */
class DemandeDevisController extends Controller
{
    public function __construct(
        private MediaService $media,
        private ClassementArtisan $classement,
        private MoteurBadges $badges,
        private ServiceNotification $notifications,
    ) {}

    /**
     * Un client y voit ses demandes envoyees, un artisan les missions reçues.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $utilisateur = $request->user();

        $requete = DemandeDevis::query()
            ->with(['photos', 'metier', 'client:id,nom,prenom,avatar_url,role', 'artisan.utilisateur:id,nom,prenom,avatar_url,role'])
            ->latest();

        if ($utilisateur->estArtisan()) {
            $requete->whereHas('artisan', fn ($r) => $r->where('utilisateur_id', $utilisateur->id));
        } elseif (! $utilisateur->estAdmin()) {
            $requete->where('client_id', $utilisateur->id);
        }

        if ($statut = $request->string('statut')->trim()->value()) {
            abort_unless(in_array($statut, DemandeDevis::STATUTS, true), 422, 'Statut inconnu.');
            $requete->where('statut', $statut);
        }

        return DemandeDevisResource::collection($requete->paginate(20)->withQueryString());
    }

    public function show(Request $request, DemandeDevis $demande): DemandeDevisResource
    {
        $this->authorize('view', $demande);

        $demande->load(['photos', 'metier', 'avis', 'client:id,nom,prenom,avatar_url,role', 'artisan.utilisateur:id,nom,prenom,avatar_url,role']);

        return new DemandeDevisResource($demande);
    }

    public function store(CreerDemandeDevisRequest $request): JsonResponse
    {
        $this->authorize('create', DemandeDevis::class);

        $donnees = $request->validated();

        $artisan = Artisan::valides()->findOr($donnees['artisan_id'], fn () => null);

        if (! $artisan) {
            return response()->json([
                'message' => "Cet artisan n'accepte pas encore de demandes.",
            ], 422);
        }

        if ($artisan->utilisateur_id === $request->user()->id) {
            return response()->json([
                'message' => 'Vous ne pouvez pas vous adresser une demande à vous-même.',
            ], 422);
        }

        try {
            $urls = collect($donnees['photos'] ?? [])
                ->map(fn (string $photo) => $this->media->enregistrerImage($photo, 'demandes'))
                ->all();
        } catch (RuntimeException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'errors' => ['photos' => [$e->getMessage()]],
            ], 422);
        }

        $demande = DB::transaction(function () use ($donnees, $request, $artisan, $urls) {
            $demande = DemandeDevis::create([
                'client_id' => $request->user()->id,
                'artisan_id' => $artisan->id,
                'metier_id' => isset($donnees['metier']) ? Metier::where('slug', $donnees['metier'])->value('id') : null,
                'titre' => $donnees['titre'],
                'description' => $donnees['description'],
                'adresse' => $donnees['adresse'],
                'latitude' => $donnees['latitude'] ?? null,
                'longitude' => $donnees['longitude'] ?? null,
                'budget_estime' => $donnees['budget_estime'] ?? null,
                'date_souhaitee' => $donnees['date_souhaitee'] ?? null,
                'statut' => DemandeDevis::STATUT_EN_ATTENTE,
            ]);

            foreach ($urls as $ordre => $url) {
                $demande->photos()->create(['url' => $url, 'ordre' => $ordre]);
            }

            return $demande;
        });

        // Une mission qui n'arrive pas est une mission perdue : c'est
        // exactement le cas où le repli SMS du §7.2 se justifie.
        // La fiche artisan porte toujours un utilisateur (clé étrangère
        // obligatoire et contrainte).
        $this->notifications->notifier(
            $artisan->utilisateur,
            'Nouvelle demande de devis',
            $demande->titre.' — '.$demande->adresse,
            type: 'demande_recue',
            donnees: ['demande_id' => $demande->id],
            important: true,
        );

        return response()->json([
            'message' => 'Demande envoyée à l’artisan.',
            'demande' => new DemandeDevisResource($demande->load(['photos', 'metier'])),
        ], 201);
    }

    /** L'artisan accepte la mission et propose son montant. */
    public function accepter(Request $request, DemandeDevis $demande): JsonResponse
    {
        $this->authorize('repondre', $demande);

        $donnees = $request->validate([
            'montant_propose' => 'required|numeric|min:0|max:100000000',
        ]);

        $demande->update([
            'statut' => DemandeDevis::STATUT_ACCEPTEE,
            'montant_propose' => $donnees['montant_propose'],
            'acceptee_at' => now(),
        ]);

        $this->prevenirClient(
            $demande,
            'Votre demande a été acceptée',
            $demande->titre.' — devis proposé : '.number_format((float) $donnees['montant_propose'], 0, ',', ' ').' FCFA',
            important: true,
        );

        return $this->reponse($demande, 'Mission acceptée.');
    }

    public function refuser(Request $request, DemandeDevis $demande): JsonResponse
    {
        $this->authorize('repondre', $demande);

        $donnees = $request->validate([
            'motif_refus' => 'sometimes|nullable|string|max:500',
        ]);

        $demande->update([
            'statut' => DemandeDevis::STATUT_REFUSEE,
            'motif_refus' => $donnees['motif_refus'] ?? null,
        ]);

        $this->prevenirClient($demande, 'Votre demande a été refusée', $demande->titre);

        return $this->reponse($demande, 'Mission refusée.');
    }

    public function demarrer(DemandeDevis $demande): JsonResponse
    {
        $this->authorize('demarrer', $demande);

        $demande->update(['statut' => DemandeDevis::STATUT_EN_COURS]);

        return $this->reponse($demande, 'Intervention démarrée.');
    }

    public function terminer(Request $request, DemandeDevis $demande): JsonResponse
    {
        $this->authorize('terminer', $demande);

        $donnees = $request->validate([
            'montant_final' => 'sometimes|nullable|numeric|min:0|max:100000000',
        ]);

        $demande->update([
            'statut' => DemandeDevis::STATUT_TERMINEE,
            'montant_final' => $donnees['montant_final'] ?? $demande->montant_propose,
            'terminee_at' => now(),
        ]);

        // Le compteur de missions terminées alimente les badges et le classement.
        $artisan = $demande->artisan;
        $this->classement->recalculer($artisan);
        $this->badges->reevaluer($artisan->fresh(['badges', 'abonnementActif.plan']));

        $this->prevenirClient(
            $demande,
            'Intervention terminée',
            'Vous pouvez maintenant noter '.($demande->artisan->utilisateur->nom ?? 'votre artisan').'.',
            important: true,
        );

        return $this->reponse($demande, 'Intervention terminée. Le client peut maintenant vous noter.');
    }

    public function annuler(Request $request, DemandeDevis $demande): JsonResponse
    {
        $this->authorize('annuler', $demande);

        $donnees = $request->validate([
            'motif_refus' => 'sometimes|nullable|string|max:500',
        ]);

        $demande->update([
            'statut' => DemandeDevis::STATUT_ANNULEE,
            'motif_refus' => $donnees['motif_refus'] ?? null,
        ]);

        return $this->reponse($demande, 'Demande annulée.');
    }

    private function reponse(DemandeDevis $demande, string $message): JsonResponse
    {
        return response()->json([
            'message' => $message,
            'demande' => new DemandeDevisResource($demande->fresh(['photos', 'metier'])),
        ]);
    }

    /**
     * Prévient le client de l'évolution de sa demande.
     * L'acceptation et la clôture sont importantes : elles appellent une
     * action de sa part, et déclenchent donc le repli SMS si le push échoue.
     */
    private function prevenirClient(DemandeDevis $demande, string $titre, string $corps, bool $important = false): void
    {
        $client = $demande->client;

        if (! $client) {
            return;
        }

        $this->notifications->notifier(
            $client,
            $titre,
            $corps,
            type: 'demande_'.$demande->statut,
            donnees: ['demande_id' => $demande->id],
            important: $important,
        );
    }
}
