<?php

namespace App\Http\Controllers;

use App\Http\Resources\ArtisanResource;
use App\Models\Artisan;
use App\Models\Favori;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * « Mes artisans de confiance » du menu profil client (§5.1.10).
 */
class FavoriController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $artisans = Artisan::query()
            ->whereIn('id', $request->user()->favoris()->select('artisan_id'))
            ->with(['utilisateur:id,nom,prenom,avatar_url,ville,quartier,role', 'metiers', 'badges'])
            ->get();

        return ArtisanResource::collection($artisans);
    }

    /** Ajoute ou retire l'artisan des favoris, selon son état actuel. */
    public function basculer(Request $request, Artisan $artisan): JsonResponse
    {
        $favori = Favori::where('user_id', $request->user()->id)
            ->where('artisan_id', $artisan->id)
            ->first();

        if ($favori) {
            $favori->delete();

            return response()->json(['message' => 'Retiré de vos artisans de confiance.', 'favori' => false]);
        }

        Favori::create(['user_id' => $request->user()->id, 'artisan_id' => $artisan->id]);

        return response()->json(['message' => 'Ajouté à vos artisans de confiance.', 'favori' => true], 201);
    }
}
