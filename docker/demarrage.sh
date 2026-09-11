#!/bin/sh
# Demarrage du conteneur Maboko API.
#
# Tout ce qui depend de l'environnement se fait ici, pas a la construction :
# les variables de Render — base de donnees, cle applicative, port — ne sont
# injectees qu'a l'execution. Mettre les caches de configuration en place au
# moment du build figerait des valeurs absentes.

set -e

echo "[maboko] preparation du conteneur"

: "${PORT:=10000}"
export PORT

# Le port impose par la plateforme est injecte dans la configuration nginx.
envsubst '${PORT}' < /etc/nginx/nginx.conf.template > /etc/nginx/nginx.conf
echo "[maboko] nginx ecoutera sur le port ${PORT}"

if [ -z "${APP_KEY}" ]; then
    echo "[maboko] ATTENTION : APP_KEY est vide."
    echo "[maboko] Generez-la avec « php artisan key:generate --show » et"
    echo "[maboko] posez-la dans les variables d'environnement du service."
fi

# Lien public vers les fichiers deposes. Recree a chaque demarrage : le
# systeme de fichiers du conteneur repart vierge a chaque deploiement.
php artisan storage:link --force >/dev/null 2>&1 || true

# Les caches sont reconstruits avec l'environnement reel du service.
php artisan config:cache
php artisan route:cache
php artisan view:cache

# Migrations au deploiement. Mettez RUN_MIGRATIONS=false si vous preferez les
# lancer a la main depuis le shell de Render.
if [ "${RUN_MIGRATIONS:-true}" = "true" ]; then
    echo "[maboko] migrations"
    php artisan migrate --force
fi

echo "[maboko] demarrage de nginx et php-fpm"
exec supervisord -c /etc/supervisord.conf
