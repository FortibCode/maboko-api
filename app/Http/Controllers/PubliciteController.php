<?php

namespace App\Http\Controllers;

use App\Http\Resources\PubliciteResource;
use App\Models\Publicite;
use App\Models\Transaction;
use App\Models\User;
use App\Services\JournalAdministration;
use App\Services\MediaService;
use App\Services\Paiement\RegistrePasserelles;
use App\Services\ServiceNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Gestion des publicités (§5.1.7).
 *
 * Côté mobile :
 *   - renvoie la publicité à afficher au lancement (active).
 *   - permet aux artisans de créer et payer leur propre campagne de boost.
 *   - permet à l'artisan de consulter ses publicités.
 * Côté admin : création, validation, refus, activation et suivi des campagnes.
 */
class PubliciteController extends Controller
{
    /** Tarifs par durée en jours (montants en FCFA). */
    public const TARIFS_DUREES = [
        3 => 3000,
        7 => 6000,
        15 => 12000,
        30 => 20000,
    ];

    public function __construct(
        private JournalAdministration $journal,
        private MediaService $media,
        private RegistrePasserelles $passerelles,
        private ServiceNotification $notifications,
    ) {}

    /**
     * Publicité à afficher au lancement — côté mobile.
     *
     * Sélectionne aléatoirement parmi les publicités en cours pour assurer
     * une rotation équitable entre tous les artisans ayant souscrit.
     */
    public function active(): JsonResponse
    {
        $publicite = Publicite::query()
            ->enCours()
            ->with([
                'artisan:id,nom,prenom,avatar_url,ville,quartier',
                'artisan.artisan:id,utilisateur_id,specialite,note_moyenne,nb_missions_terminees,bio',
            ])
            ->inRandomOrder()
            ->first();

        if (! $publicite) {
            return response()->json(['publicite' => null]);
        }

        // Compteur d'affichage — best-effort, on ne bloque pas la réponse.
        $publicite->increment('nb_affichages');

        return response()->json([
            'publicite' => new PubliciteResource($publicite->fresh('artisan')),
        ]);
    }

    /**
     * Enregistre un clic sur la publicité.
     */
    public function clic(Publicite $publicite): JsonResponse
    {
        $publicite->increment('nb_clics');

        return response()->json(['message' => 'Clic enregistré.']);
    }

    /**
     * Liste des publicités de l'artisan connecté.
     */
    public function mesPublicites(Request $request): JsonResponse
    {
        $utilisateur = $request->user();

        $publicites = Publicite::query()
            ->where('artisan_id', $utilisateur->id)
            ->with(['artisan.artisan'])
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'publicites' => PubliciteResource::collection($publicites),
            'tarifs' => self::TARIFS_DUREES,
        ]);
    }

    /**
     * Création et paiement d'une campagne publicitaire par un artisan.
     */
    public function creerCampagne(Request $request): JsonResponse
    {
        $utilisateur = $request->user();

        if ($utilisateur->role !== User::ROLE_ARTISAN) {
            return response()->json([
                'message' => 'Seuls les comptes artisans peuvent créer une campagne publicitaire.',
            ], 403);
        }

        $donnees = $request->validate([
            'titre' => 'required|string|min:3|max:150',
            'description' => 'nullable|string|max:1000',
            'image' => 'required|string',
            'duree_jours' => ['required', 'integer', Rule::in(array_keys(self::TARIFS_DUREES))],
            'operateur' => ['required', Rule::in([Transaction::OPERATEUR_AIRTEL, Transaction::OPERATEUR_MTN])],
            'telephone' => ['required', 'string', 'regex:/^\+2420[456]\d{7}$/'],
        ]);

        $duree = (int) $donnees['duree_jours'];
        $montant = (float) (self::TARIFS_DUREES[$duree] ?? 6000);

        // Téléversement ou traitement de l'image
        try {
            $imageUrl = $this->media->enregistrerImage($donnees['image'], 'publicites');
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Impossible de traiter l\'image : '.$e->getMessage(),
            ], 422);
        }

        // Création de la transaction Mobile Money
        $reference = 'PUB-'.Str::upper(Str::random(10));
        $transaction = Transaction::create([
            'user_id' => $utilisateur->id,
            'payable_type' => Publicite::class,
            'payable_id' => null,
            'montant' => $montant,
            'devise' => config('paiement.devise', 'XAF'),
            'operateur' => $donnees['operateur'],
            'reference_interne' => $reference,
            'statut' => Transaction::STATUT_INITIEE,
            'payload' => [
                'duree_jours' => $duree,
                'titre' => $donnees['titre'],
                'artisan_id' => $utilisateur->id,
            ],
        ]);

        // Déclenchement de la passerelle de paiement
        $resultat = $this->passerelles->pour($donnees['operateur'])->initier($transaction, $donnees['telephone']);

        $transaction->update([
            'statut' => $resultat->statut,
            'reference_externe' => $resultat->referenceExterne ?? $transaction->reference_externe,
            'motif_echec' => $resultat->motifEchec,
            'payee_at' => $resultat->statut === Transaction::STATUT_REUSSIE ? now() : null,
        ]);

        // Création de la publicité en statut en attente de modération admin
        $publicite = Publicite::create([
            'artisan_id' => $utilisateur->id,
            'cree_par' => $utilisateur->id,
            'titre' => $donnees['titre'],
            'description' => $donnees['description'] ?? null,
            'image_url' => $imageUrl,
            'date_debut' => null,
            'date_fin' => null,
            'actif' => false,
        ]);

        $transaction->update(['payable_id' => $publicite->id]);

        $this->journal->enregistrer(
            'publicite.soumise',
            $publicite,
            [],
            [
                'artisan_id' => $publicite->artisan_id,
                'titre' => $publicite->titre,
                'duree_jours' => $duree,
                'montant' => $montant,
            ],
        );

        return response()->json([
            'message' => 'Votre demande de publicité a été reçue ! Elle sera examinée et activée par l\'administrateur sous peu.',
            'publicite' => new PubliciteResource($publicite->load('artisan')),
            'transaction' => [
                'reference' => $transaction->reference_interne,
                'statut' => $transaction->statut,
                'montant' => $transaction->montant,
            ],
        ], 201);
    }

    /**
     * Liste toutes les publicités — côté admin.
     */
    public function index(): JsonResponse
    {
        $publicites = Publicite::query()
            ->with([
                'artisan:id,nom,prenom,avatar_url',
                'artisan.artisan:id,utilisateur_id,specialite',
                'creePar:id,nom,prenom',
            ])
            ->orderByDesc('created_at')
            ->paginate(20);

        return response()->json($publicites);
    }

    /**
     * Crée une publicité depuis le panneau admin.
     */
    public function store(Request $request): JsonResponse
    {
        $donnees = $request->validate([
            'artisan_id' => 'required|integer|exists:users,id',
            'titre' => 'required|string|max:150',
            'description' => 'nullable|string|max:1000',
            'image_url' => 'nullable|string|max:500',
            'image' => 'nullable|string',
            'date_debut' => 'nullable|date',
            'date_fin' => 'nullable|date|after:date_debut',
            'actif' => 'sometimes|boolean',
        ]);

        $artisan = User::findOrFail($donnees['artisan_id']);

        if ($artisan->role !== User::ROLE_ARTISAN) {
            return response()->json([
                'message' => 'L\'utilisateur ciblé n\'est pas un artisan.',
                'errors' => ['artisan_id' => ['L\'utilisateur ciblé n\'est pas un artisan.']],
            ], 422);
        }

        $imageUrl = $donnees['image_url'] ?? null;
        if (! empty($donnees['image'])) {
            try {
                $imageUrl = $this->media->enregistrerImage($donnees['image'], 'publicites');
            } catch (\Throwable $e) {
            }
        }

        $publicite = Publicite::create([
            'artisan_id' => $artisan->id,
            'cree_par' => $request->user()->id,
            'titre' => $donnees['titre'],
            'description' => $donnees['description'] ?? null,
            'image_url' => $imageUrl,
            'date_debut' => $donnees['date_debut'] ?? now(),
            'date_fin' => $donnees['date_fin'] ?? now()->addDays(7),
            'actif' => $donnees['actif'] ?? true,
        ]);

        $this->journal->enregistrer(
            'publicite.creee',
            $publicite,
            [],
            ['artisan_id' => $publicite->artisan_id, 'titre' => $publicite->titre],
        );

        return response()->json([
            'message' => 'Publicité créée.',
            'publicite' => new PubliciteResource($publicite->load('artisan')),
        ], 201);
    }

    /**
     * Valide et active une publicité — côté admin.
     */
    public function valider(Request $request, Publicite $publicite): JsonResponse
    {
        $duree = (int) ($request->input('duree_jours') ?? 0);

        if ($duree <= 0) {
            // Tente de récupérer la durée depuis la transaction associée
            $transaction = Transaction::where('payable_type', Publicite::class)
                ->where('payable_id', $publicite->id)
                ->latest()
                ->first();

            $duree = (int) ($transaction?->payload['duree_jours'] ?? 7);
        }

        $debut = now();
        $fin = now()->addDays($duree);

        $publicite->update([
            'actif' => true,
            'date_debut' => $debut,
            'date_fin' => $fin,
        ]);

        $this->journal->enregistrer(
            'publicite.validee',
            $publicite,
            ['actif' => false],
            ['actif' => true, 'date_debut' => $debut, 'date_fin' => $fin],
        );

        // Notification envoyée à l'artisan
        if ($publicite->artisan) {
            $this->notifications->notifier(
                $publicite->artisan,
                'Votre publicité est en ligne !',
                "Votre publicité « {$publicite->titre} » a été validée par l'administrateur. Elle est maintenant affichée aux clients pendant {$duree} jours.",
                type: 'publicite_validee',
                donnees: ['publicite_id' => $publicite->id],
                important: true,
            );
        }

        return response()->json([
            'message' => "Publicité validée et active pour {$duree} jours.",
            'publicite' => new PubliciteResource($publicite->fresh('artisan')),
        ]);
    }

    /**
     * Refuse une publicité — côté admin.
     */
    public function refuser(Request $request, Publicite $publicite): JsonResponse
    {
        $donnees = $request->validate([
            'motif' => 'nullable|string|max:300',
        ]);

        $motif = $donnees['motif'] ?? 'Le visuel ou le texte ne correspond pas aux normes de diffusion Maboko.';

        $publicite->update(['actif' => false]);

        $this->journal->enregistrer(
            'publicite.refusee',
            $publicite,
            ['actif' => $publicite->actif],
            ['actif' => false, 'motif' => $motif],
        );

        if ($publicite->artisan) {
            $this->notifications->notifier(
                $publicite->artisan,
                'Publicité non validée',
                "Votre demande de publicité « {$publicite->titre} » n'a pas pu être validée : {$motif}",
                type: 'publicite_refusee',
                donnees: ['publicite_id' => $publicite->id],
                important: true,
            );
        }

        return response()->json([
            'message' => 'Publicité refusée.',
            'publicite' => new PubliciteResource($publicite->fresh('artisan')),
        ]);
    }

    /**
     * Modifie une publicité existante.
     */
    public function update(Request $request, Publicite $publicite): JsonResponse
    {
        $donnees = $request->validate([
            'titre' => 'sometimes|string|max:150',
            'description' => 'sometimes|nullable|string|max:1000',
            'image_url' => 'sometimes|nullable|string|max:500',
            'date_debut' => 'sometimes|nullable|date',
            'date_fin' => 'sometimes|nullable|date',
        ]);

        $avant = $publicite->only(['titre', 'description', 'date_debut', 'date_fin']);
        $publicite->update($donnees);

        $this->journal->enregistrer(
            'publicite.modifiee',
            $publicite,
            $avant,
            $publicite->only(['titre', 'description', 'date_debut', 'date_fin']),
        );

        return response()->json([
            'message' => 'Publicité mise à jour.',
            'publicite' => new PubliciteResource($publicite->fresh('artisan')),
        ]);
    }

    /**
     * Active ou désactive une publicité.
     */
    public function basculerActivation(Request $request, Publicite $publicite): JsonResponse
    {
        $donnees = $request->validate([
            'actif' => 'required|boolean',
        ]);

        $avant = $publicite->actif;
        $publicite->update(['actif' => $donnees['actif']]);

        $this->journal->enregistrer(
            $donnees['actif'] ? 'publicite.activee' : 'publicite.desactivee',
            $publicite,
            ['actif' => $avant],
            ['actif' => $donnees['actif']],
        );

        return response()->json([
            'message' => $donnees['actif'] ? 'Publicité activée.' : 'Publicité désactivée.',
            'actif' => $publicite->fresh()->actif,
        ]);
    }

    /**
     * Supprime définitivement une publicité.
     */
    public function destroy(Publicite $publicite): JsonResponse
    {
        $this->journal->enregistrer(
            'publicite.supprimee',
            $publicite,
            ['titre' => $publicite->titre, 'artisan_id' => $publicite->artisan_id],
            [],
        );

        $publicite->delete();

        return response()->json(['message' => 'Publicité supprimée.']);
    }
}
