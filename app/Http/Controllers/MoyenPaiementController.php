<?php

namespace App\Http\Controllers;

use App\Models\MoyenPaiement;
use App\Models\Transaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Moyens de paiement enregistres (§5.1.10).
 *
 * On ne conserve que l'operateur et le numero : le Mobile Money confirme
 * chaque paiement par une invite envoyee au telephone du titulaire. Un numero
 * enregistre ne permet donc a personne de payer a sa place.
 */
class MoyenPaiementController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $moyens = MoyenPaiement::where('utilisateur_id', $request->user()->id)
            ->orderByDesc('par_defaut')
            ->latest()
            ->get();

        return response()->json(['data' => $moyens->map($this->presenter(...))->all()]);
    }

    public function store(Request $request): JsonResponse
    {
        $utilisateur = $request->user();

        $donnees = $request->validate([
            'operateur' => ['required', Rule::in([Transaction::OPERATEUR_AIRTEL, Transaction::OPERATEUR_MTN])],
            'telephone' => ['required', 'string', 'regex:/^\+2420[456]\d{7}$/'],
            'libelle' => 'sometimes|nullable|string|max:60',
            'par_defaut' => 'sometimes|boolean',
        ]);

        $existe = MoyenPaiement::where('utilisateur_id', $utilisateur->id)
            ->where('operateur', $donnees['operateur'])
            ->where('telephone', $donnees['telephone'])
            ->exists();

        if ($existe) {
            return response()->json([
                'message' => 'Ce numero est deja enregistre pour cet operateur.',
                'errors' => ['telephone' => ['Ce numero est deja enregistre pour cet operateur.']],
            ], 422);
        }

        // Le premier moyen enregistre devient celui par defaut : sans cela,
        // l'utilisateur devrait le designer lui-meme apres l'avoir ajoute.
        $premier = ! MoyenPaiement::where('utilisateur_id', $utilisateur->id)->exists();
        $parDefaut = $donnees['par_defaut'] ?? $premier;

        $moyen = DB::transaction(function () use ($utilisateur, $donnees, $parDefaut) {
            if ($parDefaut) {
                MoyenPaiement::where('utilisateur_id', $utilisateur->id)->update(['par_defaut' => false]);
            }

            return MoyenPaiement::create([
                'utilisateur_id' => $utilisateur->id,
                'operateur' => $donnees['operateur'],
                'telephone' => $donnees['telephone'],
                'libelle' => $donnees['libelle'] ?? null,
                'par_defaut' => $parDefaut,
            ]);
        });

        return response()->json($this->presenter($moyen), 201);
    }

    /** Designe ce moyen comme celui propose en premier. */
    public function definirParDefaut(Request $request, MoyenPaiement $moyenPaiement): JsonResponse
    {
        if ($moyenPaiement->utilisateur_id !== $request->user()->id) {
            return response()->json(['message' => 'Moyen de paiement introuvable.'], 404);
        }

        DB::transaction(function () use ($request, $moyenPaiement) {
            MoyenPaiement::where('utilisateur_id', $request->user()->id)->update(['par_defaut' => false]);
            $moyenPaiement->update(['par_defaut' => true]);
        });

        return response()->json($this->presenter($moyenPaiement->fresh()));
    }

    public function destroy(Request $request, MoyenPaiement $moyenPaiement): JsonResponse
    {
        if ($moyenPaiement->utilisateur_id !== $request->user()->id) {
            return response()->json(['message' => 'Moyen de paiement introuvable.'], 404);
        }

        $etaitParDefaut = $moyenPaiement->par_defaut;
        $moyenPaiement->delete();

        // Supprimer celui par defaut ne doit pas laisser le compte sans choix
        // privilegie : le plus recent prend le relais.
        if ($etaitParDefaut) {
            MoyenPaiement::where('utilisateur_id', $request->user()->id)
                ->latest()
                ->first()
                ?->update(['par_defaut' => true]);
        }

        return response()->json(['message' => 'Moyen de paiement retire.']);
    }

    /** @return array<string, mixed> */
    private function presenter(MoyenPaiement $moyen): array
    {
        return [
            'id' => $moyen->id,
            'operateur' => $moyen->operateur,
            'telephone' => $moyen->telephone,
            'telephoneMasque' => $moyen->telephoneMasque(),
            'libelle' => $moyen->libelle,
            'parDefaut' => $moyen->par_defaut,
        ];
    }
}
