<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * En-têtes de sécurité (§7.1).
 *
 * L'API sert des données, jamais du HTML destiné à être rendu par un
 * navigateur : ces en-têtes empêchent qu'une réponse soit détournée en
 * contenu exécutable ou affichée dans un cadre tiers.
 */
class EnTetesDeSecurite
{
    public function handle(Request $request, Closure $next): Response
    {
        $reponse = $next($request);

        // Empêche le navigateur de deviner un type MIME : une réponse JSON
        // ne doit jamais être interprétée comme du script.
        $reponse->headers->set('X-Content-Type-Options', 'nosniff');

        // Aucune page de l'API n'a vocation à être encadrée.
        $reponse->headers->set('X-Frame-Options', 'DENY');

        // Ne pas divulguer l'URL complète aux services tiers.
        $reponse->headers->set('Referrer-Policy', 'no-referrer');

        // Rien à exécuter côté navigateur depuis une réponse d'API.
        $reponse->headers->set(
            'Content-Security-Policy',
            "default-src 'none'; frame-ancestors 'none'; base-uri 'none'",
        );

        // HSTS uniquement sur une connexion déjà chiffrée : l'imposer en HTTP
        // rendrait l'API injoignable en développement local.
        if ($request->secure()) {
            $reponse->headers->set(
                'Strict-Transport-Security',
                'max-age=31536000; includeSubDomains',
            );
        }

        return $reponse;
    }
}
