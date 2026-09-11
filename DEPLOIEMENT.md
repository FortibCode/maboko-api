# Déploiement de l'API Maboko sur Render

Render ne propose pas d'environnement PHP natif : un service PHP s'y déploie
par Docker. C'est le `Dockerfile` à la racine qui est utilisé — son absence
produit l'erreur `failed to read dockerfile: open Dockerfile: no such file`.

## Configuration du service

| Réglage | Valeur |
|---|---|
| Language / Runtime | **Docker** |
| Dockerfile Path | `./Dockerfile` |
| Docker Build Context Directory | `.` |
| Health Check Path | `/up` |

Le port n'est pas à configurer : le conteneur lit la variable `PORT` que
Render lui impose et fait écouter nginx dessus.

## Variables d'environnement

À poser dans **Environment** sur le service Render.

### Indispensables

| Variable | Valeur |
|---|---|
| `APP_KEY` | générée par `php artisan key:generate --show`, format `base64:...` |
| `APP_ENV` | `production` |
| `APP_DEBUG` | `false` |
| `APP_URL` | l'adresse publique du service, par exemple `https://maboko-api.onrender.com` |
| `DB_CONNECTION` | `pgsql` |
| `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | fournis par la base PostgreSQL de Render |

Si la base Render donne une URL unique, `DATABASE_URL` suffit : Laravel la lit
à la place des six variables ci-dessus.

### Utiles

| Variable | Défaut | Rôle |
|---|---|---|
| `RUN_MIGRATIONS` | `true` | joue les migrations à chaque démarrage ; passez à `false` pour les lancer à la main |
| `LOG_CHANNEL` | `stack` | `stderr` affiche les journaux directement dans Render |
| `CACHE_STORE` | `database` | la table de cache est créée par les migrations |
| `SESSION_DRIVER` | `database` | idem |
| `FILESYSTEM_DISK` | `local` | voir la section suivante |

`APP_DEBUG=false` est impératif : l'application refuse d'ailleurs de démarrer
en production si elle vise une API en clair (§7.1 du cahier de charges).

## Les photos déposées — à décider avant la mise en service

Le système de fichiers d'un conteneur Render **repart vierge à chaque
déploiement et à chaque redémarrage**. Les photos de profil, les réalisations
du portfolio et les pièces d'identité écrites dans `storage/app` y
disparaîtraient sans prévenir.

Deux réponses possibles :

**1. Un disque persistant Render.** Ajoutez un *Disk* au service, monté sur
`/var/www/html/storage/app`. Rien à changer dans le code. Un disque n'est pas
disponible sur l'offre gratuite.

**2. Un stockage objet compatible S3** — Amazon S3, Cloudflare R2, Backblaze.
Le code le gère déjà : `MediaService` écrit sur le disque configuré et renvoie
l'URL absolue telle quelle. Il manque seulement l'adaptateur :

```
composer require league/flysystem-aws-s3-v3
```

puis `FILESYSTEM_DISK=s3` et les variables `AWS_ACCESS_KEY_ID`,
`AWS_SECRET_ACCESS_KEY`, `AWS_DEFAULT_REGION`, `AWS_BUCKET`, `AWS_URL`
(et `AWS_ENDPOINT` pour R2 ou Backblaze).

Tant qu'aucune des deux n'est en place, les photos tiennent jusqu'au prochain
déploiement, pas au-delà.

## Ce que fait le conteneur au démarrage

1. Injecte le `PORT` de Render dans la configuration nginx.
2. Recrée le lien `public/storage`.
3. Reconstruit les caches de configuration, de routes et de vues **avec
   l'environnement réel** — les mettre en cache à la construction figerait des
   valeurs absentes.
4. Joue les migrations, sauf si `RUN_MIGRATIONS=false`.
5. Lance nginx et php-fpm sous supervisor.

## Vérifier après déploiement

```
curl https://<votre-service>.onrender.com/up
curl -H 'Accept: application/json' https://<votre-service>.onrender.com/api/v1/metiers
```

La première doit répondre 200, la seconde 401 — ce qui prouve que l'API
répond et que l'authentification est bien exigée.

N'oubliez pas de reporter cette adresse dans l'application mobile :
Paramètres → Développement → Adresse du serveur, ou à la compilation avec
`--dart-define=API_BASE_URL=https://<votre-service>.onrender.com/api/v1`.
