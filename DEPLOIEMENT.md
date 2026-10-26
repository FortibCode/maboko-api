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

Le *Health Check Path* mérite d'être posé : sans lui, Render interroge `/` et
reçoit la page d'accueil de Laravel — 18 Ko à chaque sonde. `/up` répond en
quelques octets et vérifie réellement que le framework a démarré.

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

## L'envoi des SMS — sans quoi personne ne peut s'inscrire

La validation du compte repose sur un code à six chiffres envoyé par SMS. Sans
passerelle configurée, le canal par défaut (`log`) écrit ce code dans les
journaux du serveur : l'utilisateur ne le reçoit jamais et reste bloqué sur
l'écran de saisie.

Depuis la correction, l'API **refuse explicitement** dans ce cas — 503 avec le
code `passerelle_sms_absente` — au lieu de répondre « Code envoyé par SMS »
alors que rien n'est parti.

| Variable | Valeur |
|---|---|
| `SMS_DRIVER` | `twilio` |
| `TWILIO_SID` | identifiant du compte Twilio |
| `TWILIO_TOKEN` | jeton d'authentification |
| `TWILIO_FROM` | numéro expéditeur, au format international |

Le canal `log` reste accepté en local et dans les tests, jamais ailleurs.

### Essayer l'inscription sans passerelle

`OTP_NUMEROS_TEST` déclare une liste de numéros — les vôtres — pour lesquels
aucun SMS n'est tenté et le code revient directement dans la réponse HTTP :

```
OTP_NUMEROS_TEST=+242060000001,+242066123456
```

Tout autre numéro continue d'exiger une vraie passerelle. C'est la manière sûre
de tester tant que Twilio n'est pas branché.

### Phase de test ouverte, sans passerelle

Quand plusieurs personnes doivent essayer l'application et qu'aucune passerelle
n'est encore branchée :

```
OTP_EXPOSE_IN_RESPONSE=true
```

Le code revient alors dans la réponse **à l'inscription, pour n'importe quel
numéro** : l'application l'affiche et le testeur valide son compte.

Ce que ce drapeau n'ouvre **pas** : la réinitialisation de mot de passe. Hors
du poste de développement, son code n'est jamais rendu — l'exposer donnerait
accès à un compte existant, celui de l'administration compris. Sans passerelle,
le mot de passe oublié répond donc 503, et ne divulgue rien.

Ce qu'il faut avoir en tête : pendant cette phase, quelqu'un peut créer un
compte avec un numéro qui n'est pas le sien, puisque rien ne prouve plus qu'il
le possède. C'est acceptable le temps d'essais entre gens de confiance, pas
au-delà. **Retirez ce drapeau avant l'ouverture au public**, une fois Twilio
ou un autre fournisseur en place.

**N'activez pas `OTP_EXPOSE_IN_RESPONSE` en production.** Ce drapeau renvoie le
code dans la réponse HTTP : il supprime purement et simplement la vérification
par téléphone, et permet de créer un compte avec le numéro d'un tiers ou de
réinitialiser le mot de passe de n'importe qui.

## Les photos déposées

Le système de fichiers d'un conteneur Render **repart vierge à chaque
déploiement et à chaque redémarrage**. Les photos écrites dans `storage/app`
y disparaîtraient sans prévenir.

Le stockage Supabase est branché : il suffit de poser ces variables sur le
service Render et de mettre `FILESYSTEM_DISK=supabase`.

| Variable | Où la trouver |
|---|---|
| `SUPABASE_S3_KEY` | Supabase → Storage → Settings → **S3 access keys** |
| `SUPABASE_S3_SECRET` | affiché une seule fois, à la création de la clef |
| `SUPABASE_S3_REGION` | Project Settings → General → Region |
| `SUPABASE_S3_BUCKET` | le nom du bucket, `media` |
| `SUPABASE_S3_ENDPOINT` | Storage → Settings → **S3 connection** |
| `SUPABASE_STORAGE_URL` | `https://<réf>.supabase.co/storage/v1/object/public/<bucket>` — facultative : déduite du point d'entrée si absente |
| `FILESYSTEM_DISK` | `supabase` |

La clef S3 n'est **pas** la clef `anon` ni la `service_role` : ce sont deux
identifiants distincts, créés dans la section S3 de Supabase.

### Les pièces d'identité vont ailleurs

Les pièces déposées pour la vérification (§7.1) sont chiffrées avant écriture
et ne sont servies qu'à l'administration, par lien signé. Elles n'ont rien à
faire dans un bucket public : `DISQUE_PRIVE` désigne leur disque, `local` par
défaut — ce qui, sur Render, signifie qu'elles disparaissent au déploiement
suivant.

Créez un **second bucket, privé**, et déclarez-le comme un disque dédié avant
de pointer `DISQUE_PRIVE` dessus.

## Ce que fait le conteneur au démarrage

1. Injecte le `PORT` de Render dans la configuration nginx.
2. Recrée le lien `public/storage`.
3. Reconstruit les caches de configuration, de routes et de vues **avec
   l'environnement réel** — les mettre en cache à la construction figerait des
   valeurs absentes.
4. Joue les migrations, sauf si `RUN_MIGRATIONS=false`.
5. Lance php-fpm, attend qu'il accepte les connexions, puis lance nginx.
   L'ordre compte : nginx qui ouvre son port avant php-fpm renvoie des 502
   aux requêtes arrivées dans l'intervalle.

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
