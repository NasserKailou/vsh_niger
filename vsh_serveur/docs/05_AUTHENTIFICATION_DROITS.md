# Authentification, sessions, droits et audit

## Vue d'ensemble

| Élément | Choix |
|---|---|
| Identifiant | Numéro de téléphone, normalisé en E.164 (`90 12 34 56` devient `+22790123456`) |
| Mot de passe | 8 à 64 caractères, au moins une lettre et un chiffre, haché avec `password_hash` (bcrypt), re-haché automatiquement si l'algorithme évolue |
| Jeton d'accès | Opaque, 1 h (`ACCESS_TOKEN_TTL`), en-tête `Authorization: Bearer …` |
| Refresh token | Opaque, 30 jours (`REFRESH_TOKEN_TTL_DAYS`), **à usage unique et rotatif**, lié à l'appareil |
| Stockage serveur | Empreinte SHA-256 uniquement (un vol de la base ne donne aucun jeton utilisable) |
| Droits | Permissions stockées en base (`roles`, `permissions`, `role_permissions`, `user_roles`), vérifiées côté serveur sur chaque route |
| Audit | Table `audit_logs` : connexions, échecs, mots de passe, comptes, rôles, appareils |

Le portail web patient (n° de dossier + téléphone + code SMS, D-001) et l'inscription des patients seront livrés avec le module Patients (étape 4). Ils réutilisent `OtpService`, déjà en place.

## Points d'accès

| Méthode | Route | Accès | Rôle |
|---|---|---|---|
| POST | `/auth/login` | public | Connexion ; avec `device` = session mobile, sans `device` = session web |
| POST | `/auth/refresh` | public | Nouvelle paire de jetons |
| POST | `/auth/password/forgot` | public | Envoie un code SMS ; réponse identique que le numéro existe ou non |
| POST | `/auth/password/reset` | public | Nouveau mot de passe avec le code SMS ; ferme toutes les sessions |
| GET | `/me` | connecté* | Profil, rôles, permissions |
| PUT | `/auth/password` | connecté* | Changement de mot de passe ; déconnecte les autres appareils |
| POST | `/auth/logout` | connecté* | Ferme la session en cours (jeton d'accès + refresh) |
| GET | `/me/devices` | connecté | Appareils de l'utilisateur |
| DELETE | `/me/devices/{id}` | connecté | Révoque un appareil (perte, vol) |
| GET / POST | `/users` | `users.read` / `users.manage` | Liste paginée (`page`, `per_page`, `search`, `status`, `role`) / création d'un compte du personnel |
| GET / PUT | `/users/{id}` | `users.read` / `users.manage` | Consultation / modification (identité, rôles, profil professionnel) |
| POST | `/users/{id}/suspend`, `/activate`, `/reset-password` | `users.manage` | Suspension (ferme les sessions), réactivation, mot de passe temporaire |
| GET | `/roles` | `users.read` | Rôles et leurs permissions |
| POST / PUT / DELETE | `/roles`, `/roles/{code}` | `roles.manage` | Rôles personnalisés |
| GET | `/permissions` | `roles.manage` | Permissions disponibles, groupées par module |

\* Accessible même si l'utilisateur doit changer son mot de passe. Toute autre route répond alors `403 PASSWORD_CHANGE_REQUIRED`.

### Exemple de connexion mobile

```json
POST /api/v1/auth/login
{
  "phone": "90 12 34 56",
  "password": "••••••••",
  "device": { "uuid": "8c7e…", "platform": "ANDROID", "name": "Samsung A14", "app_version": "1.0.0" }
}
```

```json
{
  "success": true,
  "message": "Connexion réussie.",
  "data": {
    "tokens": {
      "token_type": "Bearer", "access_token": "…", "expires_in": 3600,
      "access_token_expires_at": "2026-09-25T19:00:00Z",
      "refresh_token": "…", "refresh_token_expires_at": "2026-10-25T18:00:00Z"
    },
    "user": { "id": "…", "roles": ["INFIRMIER"], "permissions": ["homecare.intervene", "…"], "must_change_password": false, "…": "…" }
  }
}
```

## Codes d'erreur d'authentification

| Code | HTTP | Réaction attendue de l'application |
|---|---|---|
| `INVALID_CREDENTIALS` | 401 | Afficher le message (il ne précise jamais lequel des deux champs est faux) |
| `RATE_LIMITED` | 429 | Attendre `Retry-After` secondes |
| `ACCOUNT_DISABLED` | 401/403 | Compte suspendu : contacter la clinique |
| `UNAUTHENTICATED` | 401 | Reconnexion |
| `TOKEN_EXPIRED` | 401 | Appeler `/auth/refresh` puis rejouer la requête |
| `SESSION_EXPIRED`, `SESSION_REVOKED`, `REFRESH_TOKEN_REUSED`, `INVALID_REFRESH_TOKEN` | 401 | Reconnexion. **Les données locales et la file de synchronisation sont conservées.** |
| `DEVICE_REVOKED` | 401/403 | Appareil déclaré perdu : effacer les données locales |
| `PASSWORD_CHANGE_REQUIRED` | 403 | Afficher l'écran de changement de mot de passe |
| `INVALID_OTP` | 422 | Code faux ou expiré |
| `SMS_UNAVAILABLE` | 503 | Réessayer plus tard |

## Rotation des refresh tokens et coupures réseau

Chaque connexion crée une **famille** de refresh tokens. À chaque `/auth/refresh`, l'ancien jeton est révoqué et remplacé.

- **Vol présumé** : un jeton déjà remplacé est présenté à nouveau, alors que son remplaçant a servi ou que plus de 120 s se sont écoulées. Toute la famille et ses jetons d'accès sont alors révoqués (`REFRESH_TOKEN_REUSED`), et l'événement est audité.
- **Réponse perdue** : le téléphone a envoyé `/auth/refresh`, mais la réponse n'est jamais arrivée (coupure). L'application renvoie l'ancien jeton. Dans les 120 s (`security.refresh_reuse_grace_seconds`), si le jeton émis entre-temps n'a jamais servi, il est annulé et une nouvelle paire est délivrée. L'utilisateur reste connecté.

Règle pour l'application mobile : ne remplacer le refresh token stocké qu'après avoir reçu et enregistré la réponse, et ne lancer qu'un seul renouvellement à la fois.

## Limitation des tentatives

- 5 échecs par téléphone en 15 minutes (connexion ou réinitialisation), puis `429` pendant le reste de la fenêtre. Le mot de passe correct est lui aussi refusé pendant ce délai.
- 30 échecs par adresse IP en 15 minutes.
- Codes SMS : 60 s minimum entre deux envois, 5 envois par heure et par numéro, validité 5 minutes, 5 essais par code. Chaque nouveau code invalide le précédent.
- Les identifiants sont stockés sous forme de HMAC (clé `APP_KEY`), jamais en clair.
- Paramètres : `config/security.php` (`login`, `otp`).

## Rôles et permissions

- Le code ne teste que des **codes de permission** (`'permission:users.manage'` sur la route). Les rôles sont des regroupements modifiables depuis l'API, et les changements s'appliquent dès la requête suivante.
- Deux garde-fous :
  - au moins un compte actif doit conserver `roles.manage`, sinon l'opération est refusée avec `409 LAST_ADMINISTRATOR` ;
  - le rôle `PATIENT` ne peut recevoir que des permissions `self.*` ou `*_self`.
- Les rôles fournis à l'installation ne peuvent pas être supprimés. Un rôle encore attribué ne peut pas l'être non plus.
- Un administrateur ne peut pas suspendre son propre compte.
- Le contrôle **par ligne** (quel patient, quelle équipe) sera ajouté module par module, via des classes Policy.

## Appareils

- Une connexion avec `device` enregistre l'appareil (UUID généré par l'application à l'installation).
- **Appareil partagé** : si un autre utilisateur se connecte sur le même appareil, les sessions du précédent y sont fermées. L'application doit alors effacer la base locale du précédent utilisateur **après** avoir synchronisé sa file d'attente, ou la conserver chiffrée et séparée. Ce point sera traité à l'étape 10 (application mobile).
- Un appareil révoqué ne peut plus se connecter : ses jetons sont invalidés immédiatement.

## Premier administrateur

```bash
php bin/console.php create-admin
# ou sans saisie interactive :
php bin/console.php create-admin --first-name=Aïcha --last-name=Moussa --phone="90 12 34 56" --email=
```

Le mot de passe temporaire est affiché une seule fois, et doit être changé à la première connexion.

## Maintenance

`php bin/console.php security:purge` supprime les jetons expirés, les codes OTP de plus d'un jour et les tentatives de plus de 30 jours. À planifier chaque jour (cron ou planificateur de tâches Windows).

## SMS (D-002)

| `SMS_DRIVER` | Configuration |
|---|---|
| `log` | Développement uniquement : le SMS est écrit dans le journal. **Refusé si `APP_ENV=production`.** |
| `twilio` | `TWILIO_ACCOUNT_SID`, `TWILIO_AUTH_TOKEN`, `SMS_SENDER` |
| `http` | `SMS_HTTP_URL`, `SMS_HTTP_METHOD`, `SMS_HTTP_AUTH_HEADER`, `SMS_HTTP_BODY_TEMPLATE` (ex. `{"to":"{to}","from":"{sender}","message":"{message}"}`), `SMS_SENDER` |

## Tests

`tests/Api/AuthTest.php` et `tests/Api/UsersTest.php` couvrent notamment :
- connexion et limitation des tentatives ;
- jeton expiré, rotation, réutilisation frauduleuse et réponse perdue ;
- déconnexion, changement de mot de passe obligatoire, réinitialisation par SMS (code faux, expiré, à usage unique, renvoi trop rapide, panne SMS) ;
- appareil révoqué ou partagé ;
- permissions refusées côté serveur, rôles personnalisés, garde-fous administrateur, audit.

Les tests utilisent la base `vsh_homecare_test`, reconstruite à chaque exécution.
