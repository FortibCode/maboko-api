<?php

namespace App\Http\Controllers;

use App\Models\DemandeDevis;
use App\Models\Transaction;
use App\Models\User;
use App\Services\JournalAdministration;
use App\Services\ServiceNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DemandeDevisController extends Controller
{
    public function __construct(
        private ServiceNotification $notifications,
        private JournalAdministration $journal,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $requete = DemandeDevis::with(['client:id,nom,prenom', 'artisan.utilisateur:id,nom,prenom']);

<<<<<<< HEAD
        if ($user->role === User::ROLE_ARTISAN) {
            $artisan = $user->artisan;
            $requete->where('artisan_id', $artisan?->id);
        } else {
            $requete->where('client_id', $user->id);
=======
        $requete = DemandeDevis::query()
            ->with(['photos', 'metier', 'client:id,nom,prenom,avatar_url,role', 'artisan.utilisateur:id,nom,prenom,avatar_url,role'])
            ->latest();

        if ($utilisateur->estArtisan()) {
            $requete->whereHas('artisan', fn ($r) => $r->where('utilisateur_id', $utilisateur->id));
        } elseif (! $utilisateur->estAdmin()) {
            $requete->where('client_id', $utilisateur->id);
>>>>>>> 191747fdc8be36e8985fed8248eddadb6cd3fa61
        }

        $demandes = $requete->latest()->get()->map(fn ($d) => $this->formaterDemande($d));

        return response()->json(['data' => $demandes]);
    }

    public function show(Request $request, DemandeDevis $demande): JsonResponse
    {
        $this->authorize('view', $demande);

        $demande->load(['client:id,nom,prenom', 'artisan.utilisateur:id,nom,prenom']);

        return response()->json(['demande' => $this->formaterDemande($demande)]);
    }

    public function store(Request $request): JsonResponse
    {
        $donnees = $request->validate([
            'artisan_id' => 'required|exists:artisans,id',
            'titre' => 'required|string|max:150',
            'description' => 'required|string|max:2000',
            'adresse' => 'required|string|max:255',
            'budget_estime' => 'nullable|numeric|min:0',
            'date_souhaitee' => 'nullable|date',
            'photos' => 'nullable|array',
            'photos.*' => 'string',
        ]);

        $demande = DemandeDevis::create([
            ...$donnees,
            'client_id' => $request->user()->id,
            'statut' => 'en_attente',
        ]);

        return response()->json([
            'message' => 'Demande envoyée.',
            'demande' => $this->formaterDemande($demande->load('artisan.utilisateur')),
        ], 201);
    }

    public function accepter(Request $request, DemandeDevis $demande): JsonResponse
    {
        $this->authorize('agirArtisan', $demande);

        $donnees = $request->validate([
            'montant_propose' => 'required|numeric|min:0',
        ]);

        $demande->update([
            'statut' => 'acceptee',
            'montant_propose' => $donnees['montant_propose'],
        ]);

        return response()->json([
            'message' => 'Mission acceptée.',
            'demande' => $this->formaterDemande($demande->fresh()),
        ]);
    }

    public function refuser(Request $request, DemandeDevis $demande): JsonResponse
    {
        $this->authorize('agirArtisan', $demande);

        $donnees = $request->validate([
            'motif_refus' => 'nullable|string|max:500',
        ]);

        $demande->update([
            'statut' => 'refusee',
            'motif_refus' => $donnees['motif_refus'] ?? null,
        ]);

        return response()->json([
            'message' => 'Mission refusée.',
            'demande' => $this->formaterDemande($demande->fresh()),
        ]);
    }

    public function demarrer(Request $request, DemandeDevis $demande): JsonResponse
    {
        $this->authorize('agirArtisan', $demande);

        $demande->update(['statut' => 'en_cours']);

        return response()->json([
            'message' => 'Intervention démarrée.',
            'demande' => $this->formaterDemande($demande->fresh()),
        ]);
    }

    public function terminer(Request $request, DemandeDevis $demande): JsonResponse
    {
        $this->authorize('agirArtisan', $demande);

        $donnees = $request->validate([
            'montant_final' => 'required|numeric|min:0',
        ]);

        $demande->update([
            'statut' => 'terminee',
            'montant_final' => $donnees['montant_final'],
        ]);

        return response()->json([
            'message' => 'Intervention terminée.',
            'demande' => $this->formaterDemande($demande->fresh()),
        ]);
    }

    public function annuler(Request $request, DemandeDevis $demande): JsonResponse
    {
        $this->authorize('annulerClient', $demande);

        $demande->update(['statut' => 'annulee']);

        return response()->json([
            'message' => 'Demande annulée.',
            'demande' => $this->formaterDemande($demande->fresh()),
        ]);
    }

    /**
     * Paiement simulé du client vers l'artisan.
     */
    public function payer(Request $request, DemandeDevis $demande): JsonResponse
    {
        // 1. Vérifier que l'utilisateur est bien le client de cette demande
        if ($demande->client_id !== $request->user()->id) {
            return response()->json(['message' => 'Non autorisé.'], 403);
        }

        // 2. Vérifier que la mission est terminée
        if ($demande->statut !== 'terminee') {
            return response()->json(['message' => 'La mission n\'est pas encore terminée.'], 422);
        }

        DB::transaction(function () use ($demande, $request) {
            // 3. Mettre à jour le statut
            $demande->update(['statut' => 'payee']);

            // 4. Enregistrer la transaction financière (Simulation)
            Transaction::create([
                'utilisateur_id' => $demande->artisan->utilisateur_id,
                'montant' => $demande->montant_final ?? $demande->montant_propose,
                'type' => 'credit_mission',
                'reference' => 'MISSION-' . $demande->id . '-' . Str::random(6),
                'description' => 'Paiement pour la mission : ' . $demande->titre,
                'statut' => 'reussi',
            ]);

            // 5. Enregistrer dans le journal d'administration
            $this->journal->enregistrer(
                'demande.payee',
                $demande,
                ['statut' => 'terminee'],
                ['statut' => 'payee', 'montant' => $demande->montant_final],
            );

            // 6. Notifier l'artisan
            if ($demande->artisan?->utilisateur) {
                $this->notifications->notifier(
                    $demande->artisan->utilisateur,
                    'Paiement reçu',
                    "Vous avez reçu un paiement de " . number_format($demande->montant_final, 0, ',', ' ') . " FCFA pour la mission : {$demande->titre}",
                    type: 'paiement',
                    important: true,
                );
            }
        });

        return response()->json([
            'message' => 'Paiement simulé avec succès.',
            'demande' => $this->formaterDemande($demande->fresh()),
        ]);
    }

    /**
     * Formate la demande pour l'API Flutter.
     */
    private function formaterDemande(DemandeDevis $demande): array
    {
        return [
            'id' => $demande->id,
            'titre' => $demande->titre,
            'description' => $demande->description,
            'adresse' => $demande->adresse,
            'budgetEstime' => (float) $demande->budget_estime,
            'montantPropose' => (float) $demande->montant_propose,
            'montantFinal' => (float) $demande->montant_final,
            'statut' => $demande->statut,
            'dateSouhaitee' => $demande->date_souhaitee?->format('Y-m-d'),
            'photos' => $demande->photos ?? [],
            'motifRefus' => $demande->motif_refus,
            'clientNom' => $demande->client ? trim($demande->client->prenom . ' ' . $demande->client->nom) : null,
            'artisanNom' => $demande->artisan?->utilisateur ? trim($demande->artisan->utilisateur->prenom . ' ' . $demande->artisan->utilisateur->nom) : null,
            'metier' => $demande->metier,
            'created_at' => $demande->created_at?->toIso8601String(),
        ];
    }
}