<?php

namespace App\Http\Controllers;

use App\Models\Report;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    /**
     * Liste des signalements. Reservee a l'administration : un signalement
     * expose l'identite de son auteur.
     */
    public function index(Request $request): JsonResponse
    {
        if (! $request->user()->estAdmin()) {
            return response()->json(['message' => 'Acces refuse.'], 403);
        }

        $signalements = Report::with('reporter:id,nom,prenom')
            ->latest()
            ->cursorPaginate(30);

        $signalements->through(fn (Report $r) => [
            'id' => (string) $r->id,
            'targetId' => $r->target_id,
            'targetType' => $r->target_type,
            'reason' => $r->reason,
            'reporterId' => (string) $r->reporter_id,
            'reporterName' => trim(($r->reporter->prenom ?? '').' '.($r->reporter->nom ?? '')),
            'createdAt' => $r->created_at?->toIso8601String(),
        ]);

        return response()->json($signalements);
    }

    public function store(Request $request): JsonResponse
    {
        $donnees = $request->validate([
            'target_id' => 'required|string|max:255',
            'target_type' => 'required|string|in:post,user',
            'reason' => 'required|string|max:1000',
        ]);

        // L'auteur du signalement vient du jeton, jamais du corps de la requete :
        // sinon n'importe qui pourrait signaler au nom d'un tiers.
        $signalement = Report::create([
            ...$donnees,
            'reporter_id' => $request->user()->id,
        ]);

        return response()->json([
            'message' => 'Signalement enregistre. Notre equipe va l examiner.',
            'report' => $signalement,
        ], 201);
    }
}
