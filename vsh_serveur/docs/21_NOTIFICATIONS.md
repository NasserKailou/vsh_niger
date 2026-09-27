# Étape 13 — Notifications push (FCM) et SMS

Décision de référence : [D-011](01_DECISIONS.md#d-011--envoi-des-notifications-par-push-et-sms-2026-09-27).

## Principe

La notification **in-app** (table `notifications`) reste la source de vérité : l'application la reçoit à la synchronisation. Le push et le SMS ne servent qu'à **prévenir** : s'ils échouent, rien n'est perdu.

```
action métier (transaction)
 ├─ notifications              ← notification in-app
 └─ notification_deliveries    ← PUSH et/ou SMS à envoyer (PENDING)
        │   (validée avec l'action, ou annulée avec elle)
        ▼
php bin/console.php notifications:dispatch   (cron chaque minute)
 ├─ PUSH → FCM HTTP v1 → appareils actifs de l'utilisateur
 └─ SMS  → passerelle SMS (D-002), patients seulement
```

- Aucun appel réseau pendant une transaction : une passerelle lente ou en panne ne ralentit ni ne bloque aucune action.
- Une action annulée (rollback) n'envoie rien.
- Aucune donnée médicale : titre et texte sont ceux de la notification, déjà rédigés sans détail (« Un résultat d'examen est disponible dans votre dossier. »). Le push porte en plus l'identifiant et le type de l'élément, pour ouvrir le bon écran.

## Canaux

| Canal | Destinataires | Mise en file si |
|---|---|---|
| PUSH | Personnel et patients | `PUSH_DRIVER` ≠ `none`, paramètre `notifications.push_enabled` actif, au moins un appareil enregistré et non révoqué |
| SMS | Patients uniquement (numéro du compte, y compris pour les dossiers de la famille, D-009) | `notifications.sms_mode` ≠ `OFF` et type présent dans `notifications.sms_types` |

Modes SMS (`notifications.sms_mode`, **Administration → Paramètres**) :

| Mode | Effet |
|---|---|
| `FALLBACK` (défaut) | SMS **seulement si le push n'a pas abouti** : pas d'application, push désactivé, jeton invalide ou échec définitif. Si le push est en cours de nouvelle tentative, le SMS attend son issue. |
| `ALWAYS` | SMS en plus du push |
| `OFF` | Aucun SMS de notification (les codes OTP ne sont pas concernés) |

Types envoyables par SMS (`notifications.sms_types`) : `PATIENT_APPROVED`, `PATIENT_REJECTED`, `APPOINTMENT_STATUS`, `HOMECARE_STATUS`, `EXAM_RESULT`, `PRESCRIPTION_SIGNED`, `INVOICE_ISSUED`. Les cinq premiers sont sélectionnés par défaut, car chaque SMS est facturé. Texte : « *Nom de la clinique* : *texte de la notification* ».

## Règles d'envoi

- Les push passent avant les SMS dans un même passage.
- Une notification **déjà lue** dans l'application n'est plus envoyée.
- Au-delà de `NOTIFY_EXPIRE_HOURS` (12 h), l'envoi est abandonné (`SKIPPED`) : « l'équipe est en route » n'a plus de sens le lendemain d'une panne.
- Compte suspendu : aucun envoi.
- Échec temporaire (service injoignable, quota, erreur 5xx, configuration à corriger) : nouvelle tentative après 1, 5, 15 puis 60 minutes, puis `FAILED` au bout de `NOTIFY_MAX_ATTEMPTS` tentatives (5).
- Push reçu par au moins un appareil : `SENT`, sans nouvel essai vers les autres, qui sonneraient deux fois. `notifications.pushed_at` est renseigné.
- Jeton déclaré invalide par FCM (application désinstallée, jeton d'un autre projet) : supprimé.
- Un verrou nommé MySQL (`GET_LOCK`) empêche deux exécutions simultanées d'envoyer deux fois le même message.
- Les passerelles ne sont construites que par le répartiteur : une configuration FCM ou SMS erronée ne bloque aucune action métier. L'envoi reste en attente, visible dans la supervision et le journal.

## Jetons push

| Route | Accès | Effet |
|---|---|---|
| `POST /push-tokens` `{token, provider?: "FCM"}` | session ouverte **depuis l'application** (appareil enregistré), sinon `409 DEVICE_REQUIRED` | Enregistre ou renouvelle le jeton de l'appareil de la session. Si le même jeton était lié à un autre appareil (téléphone passé à un autre utilisateur), il lui est retiré. Réponse : `{push_enabled}`. |
| `DELETE /push-tokens` | session | Plus de push sur cet appareil |
| `POST /auth/logout` | session | Supprime aussi le jeton de l'appareil |

Un appareil **révoqué** ne reçoit plus rien (jetons filtrés sur `devices.revoked_at IS NULL`).

## Supervision

`GET /notification-deliveries` (permission `sync.supervise`) renvoie :

- les réglages actifs : pilote et état du push, nombre d'appareils, pilote, mode et types SMS ;
- les volumes des 7 derniers jours par canal et par état ;
- le nombre d'envois en attente ;
- les 20 derniers problèmes : canal, type, tentatives, motif technique.

Ni le texte, ni le destinataire, ni le numéro n'y figurent.

## Configuration (`.env`)

| Variable | Valeurs |
|---|---|
| `PUSH_DRIVER` | `none` (défaut), `log` (développement : écrit dans `storage/logs`), `fcm` |
| `FCM_CREDENTIALS_FILE` | Fichier JSON du compte de service Firebase (console Firebase → Paramètres du projet → Comptes de service → Générer une clé privée). Hors de `public/`, lisible par le serveur seul, jamais versionné. |
| `FCM_PROJECT_ID` | Facultatif (par défaut, `project_id` du compte de service) |
| `NOTIFY_MAX_ATTEMPTS` | 5 |
| `NOTIFY_EXPIRE_HOURS` | 12 |

Le pilote FCM n'a aucune dépendance. Il signe un JWT RS256 avec la clé du compte de service (openssl), l'échange contre un jeton OAuth2 d'une heure gardé en mémoire, puis appelle `https://fcm.googleapis.com/v1/projects/{projet}/messages:send`. Sur Android, il utilise le canal `vsh_notifications`, créé par l'application.

Puis, dans **Administration → Paramètres → Notifications** : activer `notifications.push_enabled`, et choisir le mode et les types de SMS.

## Planification

```bash
# crontab de l'utilisateur du serveur web
* * * * * cd /var/www/vsh_serveur && php bin/console.php notifications:dispatch >> storage/logs/dispatch.log 2>&1
```

Poste de développement sans cron : `php bin/console.php notifications:dispatch --watch` (passage toutes les 10 s, `--watch=30` pour 30 s).

## Application mobile

- **Alertes locales**, sans push : quand la synchronisation apporte une notification non lue plus récente que la dernière signalée, le téléphone affiche une alerte système. Au-delà de trois, une seule alerte résume l'arrivée. La première synchronisation ne sonne pas pour tout l'historique. Toucher l'alerte ouvre l'écran concerné (visite, dossier, agenda, soins) ou la liste des notifications.
- **Push FCM facultatif** : activé seulement si le projet Firebase est fourni à la compilation (`--dart-define=FIREBASE_API_KEY=…`, `FIREBASE_APP_ID`, `FIREBASE_SENDER_ID`, `FIREBASE_PROJECT_ID`), sans fichier `google-services.json` dans le dépôt. À la connexion, le jeton est envoyé au serveur (`POST /push-tokens`), puis renvoyé à chaque renouvellement.
  - Application ouverte : un message déclenche une synchronisation, puis l'alerte locale.
  - Application en arrière-plan : le système affiche le message, sans alerte locale en double.
  - À la déconnexion, le jeton est détruit auprès de Firebase et les alertes affichées sont effacées.
- Tout fonctionne avec le push désactivé : les notifications arrivent à la synchronisation (au plus toutes les 5 minutes, application ouverte).

## Tests

- `tests/Api/NotificationDeliveryTest.php` (16 tests) :
  - jeton lié à l'appareil, refusé sans appareil ou mal formé, transféré d'un utilisateur à l'autre, supprimé à la déconnexion ;
  - push au personnel, jamais de SMS au personnel ;
  - SMS au patient sans application ;
  - repli : SMS ignoré quand le push arrive, en attente pendant les nouvelles tentatives du push, envoyé quand le push échoue définitivement ou que le jeton est invalide ;
  - mode `ALWAYS` ;
  - notification lue ou expirée non envoyée ;
  - types non sélectionnés et mode `OFF` ;
  - rien en file après un rollback ;
  - validation des paramètres ;
  - supervision sans contenu ni numéro.
- `tests/Unit/FcmPushGatewayTest.php` (transport simulé, sans réseau) :
  - JWT vérifié avec la clé publique ;
  - jeton OAuth réutilisé, puis renouvelé une fois après un 401 ;
  - message conforme à l'API v1 ;
  - 404, 403 `SENDER_ID_MISMATCH` et 400 portant sur le jeton traités comme jeton invalide ;
  - 429, 5xx, 400 portant sur le message et service injoignable traités comme échecs temporaires.
- Mobile, `test/notification_alerts_test.dart` : arrivées signalées une seule fois et dans l'ordre, pas d'avalanche à la première synchronisation, lien vers l'écran.
- Suite complète verte en PHP 8.2 et 7.3 (207 tests), et 46 tests Flutter.

Vérification sur le serveur XAMPP (pilotes `log`) :

1. une infirmière connectée depuis un appareil enregistre son jeton ;
2. une notification lui est adressée, ainsi qu'au patient de démonstration (qui n'a pas l'application) ;
3. `notifications:dispatch` affiche « envoyé(s) 2 », et le journal contient le push de l'infirmière et le SMS de repli du patient (« Vision Homecare Services : L'équipe de soins est en route. »).

## Limites connues

- iOS : un push nécessite en plus la clé APNs dans le projet Firebase et la capacité *Push Notifications* dans Xcode. Non configurés ici : les alertes locales fonctionnent.
- La compilation Android avec les nouveaux modules (désucrage requis par `flutter_local_notifications`, Firebase) n'a pas pu être lancée sur ce poste, car les licences du SDK Android ne sont pas acceptées. L'analyse statique et les tests Dart passent.
- Pas de notification navigateur (Web Push) pour l'interface web et le portail patient.
