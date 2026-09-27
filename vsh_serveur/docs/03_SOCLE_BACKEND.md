# Socle backend (PHP ≥ 7.3, sans framework)

## Arborescence

```
vsh_serveur/
├── public/              # SEUL dossier exposé par le serveur web
│   ├── index.php        # front controller
│   └── .htaccess        # réécriture vers index.php, en-tête Authorization, fichiers cachés refusés
├── bootstrap/
│   ├── autoload.php     # autoload PSR-4 natif (fonctionne sans Composer) + vendor/ s'il existe
│   └── app.php          # .env, configuration, conteneur de services, gestion des erreurs
├── config/              # app, database, security, logging, sms — valeurs lues dans .env
├── lang/fr.php          # messages (validation…)
├── src/
│   ├── Core/            # socle technique, sans logique métier
│   │   ├── Http/        # Request, Response, ApiResponse, Kernel
│   │   ├── Routing/     # Router
│   │   ├── Middleware/  # RequestId, SecurityHeaders, Cors, ErrorBoundary, JsonBody
│   │   ├── Validation/  # Validator
│   │   ├── Exceptions/  # HttpException, ValidationException
│   │   ├── Migrations/  # Migrator, SqlSplitter
│   │   ├── Support/     # Uuid, Clock, Phone
│   │   └── Config, Container, Database, Env, ErrorHandler, Logger
│   └── Modules/         # un dossier par module métier
│       └── System/      # routes.php, HealthController
├── bin/console.php      # migrations, seeds, clé
├── database/            # migrations/ et seeds/ (voir 02_BASE_DE_DONNEES.md)
├── storage/             # logs/, uploads/, cache/ — jamais exposé
└── tests/               # Unit/, Api/
```

Le fichier `.htaccess` à la racine de `vsh_serveur/` refuse tout accès. Si le serveur web pointe par erreur sur ce dossier au lieu de `public/`, `.env`, la configuration et les journaux restent protégés.

## Cycle d'une requête

```
public/index.php
  → Request::fromGlobals()
  → Kernel::handle()
      middlewares globaux : RequestId → SecurityHeaders → Cors → ErrorBoundary
      → Router::match()               404 / 405 JSON si aucune route
      → middlewares de route          groupe /api/v1 : json_body (+ auth, permission… à l'étape 3)
      → Contrôleur::méthode(Request)  → Response
```

- **ErrorBoundary** est placé après les middlewares qui ajoutent des en-têtes : les réponses d'erreur portent aussi `X-Request-Id`, les en-têtes de sécurité et CORS.
- **Contrôleur** : reçoit la `Request` et retourne une `Response` (via `ApiResponse`). Il ne contient pas de logique métier. Son rôle se limite à valider l'entrée, appeler un service et formater la sortie.
- Un middleware se déclare par classe ou par alias, avec des paramètres : `'permission:patients.read'`.

## Organisation d'un module

```
src/Modules/Patients/
├── routes.php              return function (Router $router): void { … }
├── PatientController.php   HTTP uniquement
├── PatientService.php      règles métier, transactions, audit, journal de synchronisation
├── PatientRepository.php   SQL (requêtes préparées) — aucun SQL ailleurs
├── PatientPolicy.php       droits par ligne (qui peut voir / modifier quel patient)
└── PatientValidator.php    règles de validation par action (création, modification…)
```

Pour activer un module, ajouter son nom dans `config/app.php` → `modules`. Les dépendances sont injectées automatiquement par le conteneur (constructeurs typés).

## Réponses

```json
{ "success": true,  "message": "Opération effectuée.", "data": {}, "meta": { "page": 1, "per_page": 25, "total": 120, "total_pages": 5 } }
{ "success": false, "message": "Les données fournies sont invalides.", "code": "VALIDATION_ERROR", "errors": { "phone": ["Numéro de téléphone invalide."] } }
```

| Code | HTTP | Déclenché par |
|---|---|---|
| `BAD_REQUEST` / `INVALID_JSON` | 400 | `HttpException::badRequest()`, JSON mal formé |
| `UNAUTHENTICATED` / `TOKEN_EXPIRED` | 401 | `HttpException::unauthorized()` |
| `FORBIDDEN` | 403 | `HttpException::forbidden()` |
| `NOT_FOUND` | 404 | route ou ressource inexistante |
| `METHOD_NOT_ALLOWED` | 405 | méthode non prévue (en-tête `Allow`) |
| `CONFLICT` / `INVALID_TRANSITION` | 409 | `HttpException::conflict()` |
| `PAYLOAD_TOO_LARGE` | 413 | corps > 2 Mo (`app.max_json_bytes`) |
| `UNSUPPORTED_MEDIA_TYPE` | 415 | corps non JSON |
| `VALIDATION_ERROR` | 422 | `Validator` |
| `RATE_LIMITED` | 429 | `HttpException::tooManyRequests()` (en-tête `Retry-After`) |
| `SERVER_ERROR` | 500 | toute autre exception : message générique, détails uniquement dans le journal |

Le mode debug (détails d'exception dans la réponse) n'est jamais actif lorsque `APP_ENV=production`, même si `APP_DEBUG=true`.

## Validation

`Validator::validate($data, $rules)` renvoie uniquement les champs déclarés (liste blanche). Un champ facultatif absent n'est pas renvoyé, ce qui permet de distinguer « non modifié » de « vidé ». En cas d'erreur, il lève `ValidationException` (422).

```php
$data = $validator->validate($request->json(), [
    'first_name' => 'required|string|max:100',
    'sex'        => 'required|in:M,F',
    'phone'      => 'nullable|phone',          // normalisé en +227XXXXXXXX
    'birth_date' => 'nullable|date',
    'latitude'   => 'nullable|latitude',
]);
```

## Base de données

`Database` : PDO avec requêtes préparées natives, `sql_mode` strict, fuseau UTC. Méthodes : `fetchOne`, `fetchAll`, `fetchValue`, `execute`, `insert`, `update`, `transaction`. Les noms de tables et colonnes passés à `insert` et `update` sont vérifiés, et les valeurs sont toujours liées.

## Journal

`storage/logs/app-AAAA-MM-JJ.log` contient une ligne JSON par événement, avec `request_id`. Les clés contenant `password`, `token` ou `secret`, ainsi que `otp`, `authorization` et `app_key`, sont masquées. Les traces d'exception n'incluent pas les valeurs des arguments.

## Console

```bash
php bin/console.php migrate                 # migrations en attente
php bin/console.php migrate:status
php bin/console.php seed                    # seeds idempotents
php bin/console.php migrate:fresh --force --seed   # APP_ENV=local|testing uniquement : SUPPRIME toutes les tables
php bin/console.php key:generate            # nouvelle APP_KEY
```

## Tests

```bash
composer install          # PHPUnit 9 (compatible PHP 7.3)
php vendor/bin/phpunit    # tests unitaires + tests du cycle HTTP complet
```

Les tests `Api/` construisent l'application complète et envoient des requêtes au `Kernel` sans serveur web.

Les tests `Api/` utilisent la base `vsh_homecare_test` (définie dans `phpunit.xml.dist`), supprimée et reconstruite à chaque exécution par `tests/bootstrap.php`. Par sécurité, son nom doit se terminer par `_test`.

Dernière vérification (2026-09-27, étape 9e) : lint et exécution complète des tests sous **PHP 7.3.33** et **PHP 8.2.12**, 181 tests et 2526 assertions au vert.
