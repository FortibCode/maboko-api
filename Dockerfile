# Image de production Maboko API.
#
# Render ne propose pas d'environnement PHP natif : un service PHP s'y
# deploie obligatoirement par Docker, et la plateforme cherche un fichier
# « Dockerfile » a la racine du depot. Son absence produit exactement
# l'erreur « failed to read dockerfile: open Dockerfile: no such file ».
#
# L'image sert Laravel derriere nginx + php-fpm, les deux tenus par
# supervisor. La configuration qui depend de l'environnement — le port en
# particulier — est resolue au demarrage, pas a la construction.

# ---------------------------------------------------------------------------
# 1. Dependances PHP
# ---------------------------------------------------------------------------
FROM composer:2 AS dependances

WORKDIR /app

# Le code entier, pas seulement composer.json : l'autoloader optimise
# parcourt « app », « database » et les autres dossiers declares pour en
# dresser la carte des classes.
COPY . .

# --no-scripts : « package:discover » a besoin des extensions PHP qui ne sont
# installees que dans l'image finale. Il y tourne, une fois le code complet.
RUN composer install \
        --no-dev \
        --no-scripts \
        --no-interaction \
        --prefer-dist \
        --optimize-autoloader

# ---------------------------------------------------------------------------
# 2. Image de service
# ---------------------------------------------------------------------------
FROM php:8.3-fpm-alpine AS production

# Bibliotheques d'execution, puis extensions compilees avec leurs en-tetes de
# developpement — retires aussitot pour ne pas les traîner dans l'image.
RUN apk add --no-cache \
        nginx \
        supervisor \
        gettext \
        postgresql-libs \
        libzip \
    && apk add --no-cache --virtual .build-deps \
        $PHPIZE_DEPS \
        postgresql-dev \
        libzip-dev \
    && docker-php-ext-install -j"$(nproc)" \
        pdo_pgsql \
        pgsql \
        bcmath \
        zip \
        pcntl \
        opcache \
    && apk del .build-deps

WORKDIR /var/www/html

COPY --from=dependances /app/vendor ./vendor
COPY . .

COPY docker/php.ini /usr/local/etc/php/conf.d/maboko.ini
COPY docker/nginx.conf.template /etc/nginx/nginx.conf.template
COPY docker/supervisord.conf /etc/supervisord.conf
COPY docker/demarrage.sh /usr/local/bin/demarrage
COPY docker/nginx-apres-fpm.sh /usr/local/bin/nginx-apres-fpm
RUN chmod +x /usr/local/bin/demarrage /usr/local/bin/nginx-apres-fpm

# Le manifeste des paquets se calcule ici : le code est complet et PHP dispose
# desormais de ses extensions.
RUN php artisan package:discover --ansi

# Ces dossiers doivent exister avant tout demarrage : « view:cache » refuse
# de tourner sans storage/framework/views, et Docker ne cree pas de
# repertoire vide.
RUN mkdir -p \
        storage/framework/cache/data \
        storage/framework/sessions \
        storage/framework/views \
        storage/logs \
        storage/app/public \
        storage/app/private \
        bootstrap/cache

# php-fpm tourne sous « www-data » : il doit pouvoir ecrire les caches, les
# journaux et les fichiers deposes.
RUN chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R ug+rwX storage bootstrap/cache

# Valeur indicative : Render impose la sienne par la variable PORT, lue au
# demarrage.
EXPOSE 10000

CMD ["/usr/local/bin/demarrage"]
