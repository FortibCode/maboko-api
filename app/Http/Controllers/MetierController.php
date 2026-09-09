<?php

namespace App\Http\Controllers;

use App\Http\Resources\MetierResource;
use App\Models\Metier;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Cache;

/**
 * Referentiel des metiers : ecran « Explorer les metiers » (§5.1.5).
 */
class MetierController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $recherche = $request->string('q')->trim()->value();

        // Le referentiel bouge rarement : il est mis en cache une heure.
        $metiers = Cache::remember(
            'metiers.actifs.'.md5($recherche),
            now()->addHour(),
            fn () => Metier::query()
                ->where('actif', true)
                ->when($recherche !== '', fn ($r) => $r->where('nom', 'like', "%{$recherche}%"))
                ->withCount(['artisans' => fn ($r) => $r->where('statut_validation', 'valide')])
                ->orderBy('ordre')
                ->get(),
        );

        return MetierResource::collection($metiers);
    }

    public function show(string $slug): MetierResource
    {
        $metier = Metier::where('slug', $slug)
            ->where('actif', true)
            ->withCount(['artisans' => fn ($r) => $r->where('statut_validation', 'valide')])
            ->firstOrFail();

        return new MetierResource($metier);
    }
}
