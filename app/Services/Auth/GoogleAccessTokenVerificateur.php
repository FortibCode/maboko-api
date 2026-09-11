<?php

namespace App\Services\Auth;

use Google\Auth\AccessToken;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Vérification réelle via la bibliothèque officielle Google : la signature
 * du jeton est contrôlée contre les certificats publics de Google, ainsi que
 * l'audience (notre identifiant client OAuth) et l'expiration.
 *
 * Sans GOOGLE_CLIENT_ID configuré, la connexion Google est désactivée plutôt
 * que de se rabattre sur une vérification incomplète : mieux vaut refuser
 * une fonctionnalité que délivrer un jeton d'accès sans preuve d'identité.
 */
class GoogleAccessTokenVerificateur implements VerificateurJetonGoogle
{
    public function verifier(string $jetonId): ?array
    {
        $audience = config('services.google.client_id');

        if (! $audience) {
            Log::warning('[AUTH google] tentative de connexion sans GOOGLE_CLIENT_ID configuré.');

            return null;
        }

        try {
            $payload = (new AccessToken)->verify($jetonId, ['audience' => $audience]);
        } catch (Throwable $e) {
            Log::warning('[AUTH google] jeton rejeté : '.$e->getMessage());

            return null;
        }

        if ($payload === false || empty($payload['email']) || empty($payload['sub'])) {
            return null;
        }

        // Google peut émettre un jeton pour une adresse non confirmée par
        // l'utilisateur : sans cette vérification, on créerait un compte
        // sur un e-mail que son titulaire n'a jamais validé.
        $emailVerifie = $payload['email_verified'] ?? false;
        if ($emailVerifie !== true && $emailVerifie !== 'true') {
            return null;
        }

        return [
            'sub' => (string) $payload['sub'],
            'email' => (string) $payload['email'],
            'nom' => $payload['family_name'] ?? null,
            'prenom' => $payload['given_name'] ?? null,
            'avatarUrl' => $payload['picture'] ?? null,
        ];
    }
}
