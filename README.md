# Maboko — API

API REST de la plateforme Maboko : réseau social des artisans congolais,
marketplace de métiers et service de transport « Allô Chauffeur ».

Laravel 12 · PHP 8.3 · PostgreSQL 16 · Redis 7

---

## Installation

Prérequis : PHP 8.3, Composer, Docker (pour PostgreSQL, Redis et le stockage
des médias).

```bash
git clone <dépôt> maboko-backend
cd maboko-backend

composer install
cp .env.example .env
php artisan key:generate

# PostgreSQL, Redis et MinIO
docker compose up -d

php artisan migrate --seed
php artisan serve
```

L'API répond alors sur `http://localhost:8000/api/v1`.

### Comptes de démonstration

Créés par le seeder, mot de passe commun `password` :

| Rôle      | Identifiant             | Téléphone       |
|-----------|-------------------------|-----------------|
| Admin     | `admin@maboko.cg`       | +242060000001   |
| Client    | `client@maboko.cg`      | +242060000002   |
| Artisan   | `artisan@maboko.cg`     | +242060000003   |
| Chauffeur | `chauffeur@maboko.cg`   | +242060000004   |

La connexion accepte indifféremment l'adresse e-mail ou le numéro de téléphone.

---

## Les codes OTP en développement

Aucune passerelle SMS n'est nécessaire pour développer. Avec `SMS_DRIVER=log`,
le code part dans `storage/logs/laravel.log` :

```bash
tail -f storage/logs/laravel.log | grep '\[SMS\]'
```

Pour le confort, `OTP_EXPOSE_IN_RESPONSE=true` renvoie le code directement dans
la réponse HTTP, sous la clé `debug_code`.

> **Ce drapeau doit rester à `false` en production.** À `true`, n'importe qui
> peut créer un compte avec le numéro d'un tiers ou réinitialiser son mot de
> passe : la vérification par téléphone ne protège plus rien.

---

## Vérifications avant de pousser

```bash
./vendor/bin/pint                                  # style de code
./vendor/bin/phpstan analyse --memory-limit=1G     # analyse statique
php artisan test                                   # tests
php artisan migrate:fresh --seed                   # migrations sur base vierge
```

Ces quatre commandes tournent aussi en intégration continue
(`.github/workflows/ci.yml`). La dernière est un garde-fou : les migrations
doivent aboutir sur une base réellement vide, à tout moment.

---

## Parcours d'authentification

L'inscription passe **obligatoirement** par la vérification OTP. Il n'existe
volontairement aucune route d'inscription directe.

**Créer un compte**

```
POST /api/v1/send-register-otp     { nom, email, telephone, password, role }
POST /api/v1/verify-register-otp   { telephone, code }        → jeton + compte
```

Les données du formulaire sont mises en attente côté serveur à la première
étape ; la seconde ne transmet que le numéro et le code. Le client ne peut donc
pas modifier son e-mail ou son rôle entre les deux appels.

**Mot de passe oublié**

```
POST /api/v1/send-otp        { telephone }
POST /api/v1/verify-otp      { telephone, code }              → reset_token
POST /api/v1/reset-password  { telephone, reset_token, password, password_confirmation }
```

Le `reset_token` est à usage unique et vaut 15 minutes. Sans lui, le changement
de mot de passe est refusé : connaître un numéro de téléphone ne suffit pas à
prendre le contrôle d'un compte. Un changement réussi révoque toutes les
sessions ouvertes.

**Limitation de débit**

| Route                      | Limite                                |
|----------------------------|---------------------------------------|
| `/login`                   | 5 / 10 min par identifiant, 20 par IP |
| `/send-otp`, `/send-register-otp` | 3 / 10 min par numéro, 10 par IP |
| `/verify-otp`, `/reset-password`  | 10 / 10 min par numéro           |
| Toute autre route API      | 60 / min                              |

Un code OTP est en outre invalidé après 5 tentatives erronées.

---

## Marketplace

**Explorer les métiers** (§5.1.5)

```
GET  /api/v1/metiers            ?q=plomb
GET  /api/v1/metiers/{slug}
```

**Rechercher un artisan** (§4.1)

```
GET  /api/v1/artisans
```

| Filtre | Valeurs |
|---|---|
| `metier` | slug du référentiel (`plombier`, `menuisier`…) |
| `latitude` + `longitude` | obligatoires ensemble ; `rayon_km` par défaut 15 |
| `note_min` | 0 à 5 |
| `badge` | slug (`maitre-artisan`, `profil-verifie`…) |
| `ville`, `q` | texte libre |
| `tri` | `pertinence` (défaut), `note`, `distance`, `missions` |

Le tri par défaut suit le `score_classement`, recalculé à partir de la note, du
volume de missions, du poids des badges et du multiplicateur de la formule
d'abonnement — c'est l'impact sur le classement prévu au §4.5.

**Fiche artisan** (§5.1.6)

```
GET   /api/v1/artisans/{id}          profil, métiers, badges, 5 derniers avis
GET   /api/v1/artisans/{id}/avis     avis paginés
GET   /api/v1/artisans/me            sa propre fiche
PATCH /api/v1/artisans/{id}          modification (propriétaire ou admin)
```

Le téléphone et l'adresse e-mail ne sortent jamais sur une fiche publique.

**Demande de devis** (§5.1.7 côté client, §5.2.2 côté artisan)

```
GET  /api/v1/demandes                       ?statut=en_attente
POST /api/v1/demandes                       artisan_id, titre, description,
                                            adresse, budget_estime, photos[]
GET  /api/v1/demandes/{id}
POST /api/v1/demandes/{id}/accepter         artisan — montant_propose
POST /api/v1/demandes/{id}/refuser          artisan — motif_refus
POST /api/v1/demandes/{id}/demarrer         artisan
POST /api/v1/demandes/{id}/terminer         artisan — montant_final
POST /api/v1/demandes/{id}/annuler          client
POST /api/v1/demandes/{id}/avis             client — note 1-5, commentaire
```

Cycle de vie : `en_attente → acceptee → en_cours → terminee`, avec `refusee`
(artisan) et `annulee` (client) comme sorties. Chaque transition est gardée par
une policy : l'artisan destinataire est le seul à pouvoir accepter, le client le
seul à pouvoir annuler ou noter, et une intervention terminée ne peut plus être
annulée. Les photos sont acceptées en URL ou en base64 et stockées comme
fichiers sur le disque configuré.

---

## Conventions

- Toutes les routes sont préfixées `/api/v1`.
- Les listes sont paginées par curseur (`data`, `next_cursor`).
- Les erreurs de validation renvoient `422` avec `{ message, errors }`.
- Les messages destinés à l'utilisateur sont en français (`lang/fr`).
- L'identité de l'auteur d'une action est **toujours** déduite du jeton
  d'authentification, jamais lue dans le corps de la requête.
