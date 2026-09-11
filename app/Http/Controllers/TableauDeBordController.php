<?php

namespace App\Http\Controllers;

use App\Http\Resources\BadgeResource;
use App\Http\Resources\DemandeDevisResource;
use App\Models\Artisan;
use App\Models\DemandeDevis;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Tableau de bord du compte connecté.
 *
 * Sert deux écrans : « Mon profil » côté client (§5.1.10) et « Tableau de
 * bord artisan » (§5.2.1). Les chiffres affichés dans l'application étaient
 * jusqu'ici écrits en dur ou lus dans le stockage local du téléphone.
 */
class TableauDeBordController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $utilisateur = $request->user();

        return response()->json(
            $utilisateur->estArtisan()
                ? $this->pourArtisan($utilisateur)
                : $this->pourClient($utilisateur),
        );
    }

    private function pourClient(User $client): array
    {
        $parStatut = $this->compterParStatut(
            DemandeDevis::where('client_id', $client->id),
        );

        return [
            'role' => 'client',
            'demandes' => [
                'total' => array_sum($parStatut),
                'enAttente' => $parStatut[DemandeDevis::STATUT_EN_ATTENTE] ?? 0,
                'enCours' => ($parStatut[DemandeDevis::STATUT_ACCEPTEE] ?? 0)
                    + ($parStatut[DemandeDevis::STATUT_EN_COURS] ?? 0),
                'terminees' => $parStatut[DemandeDevis::STATUT_TERMINEE] ?? 0,
            ],
            'favoris' => $client->favoris()->count(),
            'avisDeposes' => $client->avisDeposes()->count(),
            'montantEngage' => (float) DemandeDevis::where('client_id', $client->id)
                ->where('statut', DemandeDevis::STATUT_TERMINEE)
                ->sum('montant_final'),
            'dernieresDemandes' => DemandeDevisResource::collection(
                DemandeDevis::where('client_id', $client->id)
                    ->with(['artisan.utilisateur:id,nom,prenom,avatar_url,role', 'metier'])
                    ->latest()
                    ->limit(5)
                    ->get(),
            ),
        ];
    }

    private function pourArtisan(User $utilisateur): array
    {
        $artisan = Artisan::where('utilisateur_id', $utilisateur->id)
            ->with(['badges', 'abonnementActif.plan'])
            ->first();

        if (! $artisan) {
            return [
                'role' => 'artisan',
                'ficheManquante' => true,
                'message' => 'Complétez votre fiche artisan pour recevoir des missions.',
            ];
        }

        $parStatut = $this->compterParStatut(
            DemandeDevis::where('artisan_id', $artisan->id),
        );

        $revenus = DemandeDevis::where('artisan_id', $artisan->id)
            ->where('statut', DemandeDevis::STATUT_TERMINEE);

        return [
            'role' => 'artisan',
            'ficheManquante' => false,
            'missions' => [
                'total' => array_sum($parStatut),
                'enAttente' => $parStatut[DemandeDevis::STATUT_EN_ATTENTE] ?? 0,
                'enCours' => ($parStatut[DemandeDevis::STATUT_ACCEPTEE] ?? 0)
                    + ($parStatut[DemandeDevis::STATUT_EN_COURS] ?? 0),
                'terminees' => $parStatut[DemandeDevis::STATUT_TERMINEE] ?? 0,
            ],
            'revenus' => [
                'total' => (float) (clone $revenus)->sum('montant_final'),
                'moisCourant' => (float) (clone $revenus)
                    ->where('terminee_at', '>=', now()->startOfMonth())
                    ->sum('montant_final'),
                // Douze mois glissants, pour le suivi graphique (§5.2.4).
                // Un seul passage en base plutot qu'une requete par mois :
                // l'ecran s'ouvre parfois sur une connexion lente.
                'parMois' => $this->revenusParMois(clone $revenus),
            ],
            'noteMoyenne' => (float) $artisan->note_moyenne,
            'nbAvis' => $artisan->nb_avis,
            'badges' => BadgeResource::collection($artisan->badges),
            'abonnement' => [
                'plan' => $artisan->abonnementActif?->plan->nom ?? 'Gratuit',
                'slug' => $artisan->abonnementActif?->plan->slug ?? 'gratuit',
                'finLe' => $artisan->abonnementActif?->fin?->toDateString(),
            ],
            'dernieresDemandes' => DemandeDevisResource::collection(
                DemandeDevis::where('artisan_id', $artisan->id)
                    ->where('statut', DemandeDevis::STATUT_EN_ATTENTE)
                    ->with(['client:id,nom,prenom,avatar_url,role', 'metier'])
                    ->latest()
                    ->limit(5)
                    ->get(),
            ),
        ];
    }

    /**
     * Un seul passage en base pour tous les compteurs, plutôt qu'une
     * requête par statut.
     *
     * @return array<string, int>
     */
    private function compterParStatut(Builder $requete): array
    {
        return $requete
            ->select('statut', DB::raw('count(*) as total'))
            ->groupBy('statut')
            ->pluck('total', 'statut')
            ->map(fn ($total) => (int) $total)
            ->all();
    }

    /**
     * Revenus des douze derniers mois, mois vides compris.
     *
     * Sans les mois a zero, la courbe sauterait d'un mois actif au suivant et
     * donnerait a lire une activite continue qui n'existe pas.
     *
     * @param  Builder<DemandeDevis>  $revenus
     * @return list<array{mois: string, total: float}>
     */
    private function revenusParMois($revenus): array
    {
        $debut = now()->copy()->subMonths(11)->startOfMonth();

        // Le regroupement par mois se fait en PHP, pas en SQL : la fonction
        // de formatage de date porte un nom different selon le moteur
        // (strftime, date_format, to_char), et le volume tient largement en
        // memoire — les missions terminees d'un seul artisan sur douze mois.
        $sommes = (clone $revenus)
            ->where('terminee_at', '>=', $debut)
            ->get(['terminee_at', 'montant_final'])
            ->groupBy(fn ($mission) => $mission->terminee_at->format('Y-m'))
            ->map(fn ($mois) => (float) $mois->sum('montant_final'));

        $serie = [];

        for ($recul = 11; $recul >= 0; $recul--) {
            $mois = now()->copy()->subMonths($recul)->format('Y-m');
            $serie[] = ['mois' => $mois, 'total' => (float) ($sommes[$mois] ?? 0)];
        }

        return $serie;
    }
}
