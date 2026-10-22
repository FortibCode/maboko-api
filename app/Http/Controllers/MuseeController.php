<?php

namespace App\Http\Controllers;

use App\Http\Resources\MuseeResource;
use App\Models\MuseeSavoirFaire;
use App\Models\User;
use App\Services\JournalAdministration;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Musée du savoir-faire (§5.1.7).
 *
 * Côté mobile : liste publique des artisans mis en avant.
 * Côté admin : validation ou rejet des candidats proposés automatiquement.
 */
class MuseeController extends Controller
{
    public function __construct(
        private JournalAdministration $journal,
    ) {}

    /**
     * Liste publique — appelée par le mobile.
     *
     * Ne renvoie que les entrées validées, triées par ordre manuel puis
     * par note moyenne (un artisan mis en avant manuellement reste en tête).
     */
    public function index(): AnonymousResourceCollection
    {
        $entrees = MuseeSavoirFaire::query()
            ->valides()
            ->with([
                'artisan:id,nom,prenom,avatar_url,role,ville,quartier',
                'artisan.artisan:id,utilisateur_id,specialite,note_moyenne,nb_missions_terminees,bio,zone_intervention',
                'artisan.artisan.metiers:id,nom,slug',
            ])
            ->join('artisans', 'artisans.utilisateur_id', '=', 'musee_savoir_faire.artisan_id')
            ->orderByDesc('musee_savoir_faire.ordre')
            ->orderByDesc('artisans.note_moyenne')
            ->orderByDesc('artisans.nb_missions_terminees')
            ->select('musee_savoir_faire.*')
            ->get();

        return MuseeResource::collection($entrees);
    }

    /**
     * Liste pour l'admin : tous les candidats en attente + toutes les
     * entrées validées + les ignorés dont le délai est écoulé.
     */
    public function adminIndex(): JsonResponse
    {
        // Candidats à proposer (nouveaux ou ignorés depuis 30 jours).
        $aProposer = MuseeSavoirFaire::query()
            ->aProposer()
            ->with([
                'artisan:id,nom,prenom,avatar_url,ville,quartier',
                'artisan.artisan:id,utilisateur_id,specialite,note_moyenne,nb_missions_terminees',
            ])
            ->get()
            ->map(fn (MuseeSavoirFaire $m) => [
                'id' => $m->id,
                'artisanId' => $m->artisan_id,
                'nomComplet' => trim(($m->artisan->prenom ?? '').' '.$m->artisan->nom),
                'avatarUrl' => $m->artisan->avatar_url,
                'ville' => $m->artisan->ville,
                'quartier' => $m->artisan->quartier,
                'specialite' => $m->artisan->artisan?->specialite,
                'noteMoyenne' => (float) ($m->artisan->artisan?->note_moyenne ?? 0),
                'nbMissionsTerminees' => $m->artisan->artisan?->nb_missions_terminees ?? 0,
                'statut' => $m->statut,
            ]);

        // Déjà validés.
        $valides = MuseeSavoirFaire::query()
            ->valides()
            ->with([
                'artisan:id,nom,prenom,avatar_url,ville,quartier',
                'artisan.artisan:id,utilisateur_id,specialite,note_moyenne,nb_missions_terminees',
                'validePar:id,nom,prenom',
            ])
            ->orderByDesc('ordre')
            ->get()
            ->map(fn (MuseeSavoirFaire $m) => [
                'id' => $m->id,
                'artisanId' => $m->artisan_id,
                'nomComplet' => trim(($m->artisan->prenom ?? '').' '.$m->artisan->nom),
                'avatarUrl' => $m->artisan->avatar_url,
                'ville' => $m->artisan->ville,
                'quartier' => $m->artisan->quartier,
                'specialite' => $m->artisan->artisan?->specialite,
                'noteMoyenne' => (float) ($m->artisan->artisan?->note_moyenne ?? 0),
                'nbMissionsTerminees' => $m->artisan->artisan?->nb_missions_terminees ?? 0,
                'ordre' => $m->ordre,
                'valideAt' => $m->valide_at?->toIso8601String(),
                'validePar' => $m->validePar
                    ? trim(($m->validePar->prenom ?? '').' '.$m->validePar->nom)
                    : null,
            ]);

        return response()->json([
            'aProposer' => $aProposer,
            'valides' => $valides,
        ]);
    }

    /**
     * Valide un candidat : l'artisan entre dans le musée.
     */
    public function valider(Request $request, MuseeSavoirFaire $musee): JsonResponse
    {
        $avant = $musee->statut;

        $musee->update([
            'statut' => MuseeSavoirFaire::STATUT_VALIDE,
            'valide_par' => $request->user()->id,
            'valide_at' => now(),
            'ignore_jusqua' => null,
        ]);

        $this->journal->enregistrer(
            'musee.valide',
            $musee,
            ['statut' => $avant],
            ['statut' => MuseeSavoirFaire::STATUT_VALIDE],
        );

        return response()->json([
            'message' => 'Artisan ajouté au musée.',
            'statut' => $musee->fresh()->statut,
        ]);
    }

    /**
     * Ignore un candidat pendant 30 jours.
     */
    public function ignorer(Request $request, MuseeSavoirFaire $musee): JsonResponse
    {
        $avant = $musee->statut;

        $musee->update([
            'statut' => MuseeSavoirFaire::STATUT_IGNORE,
            'ignore_jusqua' => now()->addDays(30),
        ]);

        $this->journal->enregistrer(
            'musee.ignore',
            $musee,
            ['statut' => $avant],
            ['statut' => MuseeSavoirFaire::STATUT_IGNORE],
        );

        return response()->json([
            'message' => 'Candidat ignoré pour 30 jours.',
            'statut' => $musee->fresh()->statut,
        ]);
    }

    /**
     * Bannit définitivement un artisan du musée.
     */
    public function bannir(Request $request, MuseeSavoirFaire $musee): JsonResponse
    {
        $avant = $musee->statut;

        $musee->update([
            'statut' => MuseeSavoirFaire::STATUT_BANNI,
            'ignore_jusqua' => null,
        ]);

        $this->journal->enregistrer(
            'musee.banni',
            $musee,
            ['statut' => $avant],
            ['statut' => MuseeSavoirFaire::STATUT_BANNI],
        );

        return response()->json([
            'message' => 'Artisan banni du musée.',
            'statut' => $musee->fresh()->statut,
        ]);
    }

    /**
     * Retire un artisan du musée (retour à l'état candidat).
     */
    public function retirer(Request $request, MuseeSavoirFaire $musee): JsonResponse
    {
        $avant = $musee->statut;

        $musee->update([
            'statut' => MuseeSavoirFaire::STATUT_CANDIDAT,
            'valide_par' => null,
            'valide_at' => null,
        ]);

        $this->journal->enregistrer(
            'musee.retire',
            $musee,
            ['statut' => $avant],
            ['statut' => MuseeSavoirFaire::STATUT_CANDIDAT],
        );

        return response()->json([
            'message' => 'Artisan retiré du musée.',
            'statut' => $musee->fresh()->statut,
        ]);
    }

    /**
     * Change l'ordre d'affichage manuel d'une entrée validée.
     */
    public function reordonner(Request $request, MuseeSavoirFaire $musee): JsonResponse
    {
        $donnees = $request->validate([
            'ordre' => 'required|integer|min:0',
        ]);

        $avant = $musee->ordre;
        $musee->update(['ordre' => $donnees['ordre']]);

        $this->journal->enregistrer(
            'musee.reordonne',
            $musee,
            ['ordre' => $avant],
            ['ordre' => $donnees['ordre']],
        );

        return response()->json([
            'message' => 'Ordre mis à jour.',
            'ordre' => $musee->fresh()->ordre,
        ]);
    }

    /**
     * Recalcule manuellement les candidats (l'admin peut forcer la
     * mise à jour sans attendre le cron de la nuit).
     */
    public function rafraichir(Request $request): JsonResponse
    {
        $nombre = $this->calculerCandidats();

        return response()->json([
            'message' => "{$nombre} nouveaux candidats proposés.",
            'nombre' => $nombre,
        ]);
    }

    /**
     * Logique de sélection automatique.
     *
     * Critères : note ≥ 4.5, ≥ 5 avis, ≥ 10 missions terminées, compte
     * non suspendu, statut de validation « valide ».
     *
     * Renvoie le nombre d'entrées créées.
     */
    private function calculerCandidats(): int
    {
        $artisans = User::query()
            ->where('role', User::ROLE_ARTISAN)
            ->where('statut', User::STATUT_ACTIF)
            ->whereHas('artisan', function ($q) {
                $q->where('statut_validation', 'valide')
                  ->where('note_moyenne', '>=', 4.5)
                  ->where('nb_missions_terminees', '>=', 10);
            })
            ->pluck('id');

        $crees = 0;

        foreach ($artisans as $artisanId) {
            $existant = MuseeSavoirFaire::where('artisan_id', $artisanId)->first();

            // Nouveau candidat.
            if (! $existant) {
                MuseeSavoirFaire::create([
                    'artisan_id' => $artisanId,
                    'statut' => MuseeSavoirFaire::STATUT_CANDIDAT,
                ]);
                $crees++;
                continue;
            }

            // Ignoré dont le délai est écoulé : redevient candidat.
            if ($existant->statut === MuseeSavoirFaire::STATUT_IGNORE
                && $existant->ignore_jusqua?->isPast()) {
                $existant->update(['statut' => MuseeSavoirFaire::STATUT_CANDIDAT]);
            }
        }

        return $crees;
    }
}