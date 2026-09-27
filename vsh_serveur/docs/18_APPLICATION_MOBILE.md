# Application mobile Flutter : socle hors ligne (étape 10)

Dossier : `vsh_mobile/`. Flutter 3.44 / Dart 3.12, Android et iOS. Application réservée au **personnel** (les patients utilisent le [portail](17_PORTAIL_PATIENT.md)).

## 1. Choix de la base locale : Drift (SQLite)

| Option | Décision |
|---|---|
| **Drift + SQLite** | **Retenue** |
| sqflite seul | SQL brut, sans typage ni requêtes réactives |
| Isar | Maintenance incertaine, pas de SQL relationnel, pas de chiffrement intégré |
| Hive | Clé/valeur : pas de requêtes, d'index, de jointures ni de transactions multi-tables |

Pourquoi Drift :

- **Transactions ACID** : la donnée et l'opération de la file sont écrites ensemble ou pas du tout (règle 1 du [contrat](07_SYNCHRONISATION.md)).
- **SQL relationnel** : index, jointures (file ↔ dépendances), même modèle que MySQL côté serveur.
- **Requêtes réactives** : les écrans se mettent à jour quand la synchronisation écrit.
- **Tables et requêtes typées**, générées et vérifiées à la compilation.
- **Migrations versionnées** (`schemaVersion`).
- **Chiffrement** : `package:sqlite3` compile **SQLite3MultipleCiphers** (licence MIT) à la place de SQLite (`hooks.user_defines.sqlite3.source: sqlite3mc` dans `pubspec.yaml`).
- **Tests rapides** : base en mémoire, sans téléphone.

## 2. Architecture

```
lib/
  config/env.dart               adresse de l'API (--dart-define), intervalles
  theme/app_theme.dart          jetons de la charte (docs/08), clair et sombre
  database/                     tables Drift, ouverture chiffrée, écriture de l'état serveur
  network/                      client HTTP (jeton, renouvellement unique), API de synchronisation
  authentication/               stockage sécurisé, connexion, session, écrans
  synchronization/              file, moteur, planificateur, fournisseurs, centre de synchronisation
  patients/                     dépôt hors ligne, liste, fiche, formulaire
  shared/sync_status_badge.dart pastille d'état et bandeau hors connexion
  routing/app_router.dart       routes et redirections selon la session
```

Flux : **écran → dépôt → (transaction : table locale + file) → moteur → API REST → MySQL**, puis **API → récupération → tables locales → écrans (flux réactifs)**.

## 3. Tables locales

| Table | Rôle |
|---|---|
| `patients` | Dossiers du périmètre (colonnes de recherche + état serveur complet en JSON, `version`) |
| `records` | Autres entités synchronisées (`entity`, `id`, `patient_id`, `version`, `data`) |
| `sync_operations` | File : `local_id`, `op_id` (idempotence), `entity`, `entity_id` (= identifiant serveur), `operation` (CREATE/UPDATE/DELETE/ACTION), `action`, `payload`, `base`, `base_version`, `depends_on`, `status`, `retry_count`, `next_attempt_at`, `last_error`, `server_result`, `created_at`, `updated_at` |
| `sync_meta` | Curseur de récupération, date de dernière synchronisation, propriétaire de la base |

## 4. Moteur de synchronisation

### États d'une opération

`PENDING` → `SENDING` → `APPLIED`, ou :

| Statut | Suite |
|---|---|
| `CONFLICT` | La valeur du serveur est conservée et l'état serveur est écrit localement. Visible dans « À traiter » jusqu'à « Compris ». |
| `REJECTED` | Visible dans « À traiter », jamais supprimée en silence. « Abandonner » annule la saisie locale : une création jamais acceptée est retirée, une modification reprend ses valeurs `base`. |
| `DEFERRED` | Renvoi plus tard, avec attente progressive. |
| `ERROR` | Renvoi plus tard, avec attente progressive. |

Attente progressive : 15 s, 30 s, 1 min…, plafonnée à 1 h. Le bouton « Réessayer » renvoie tout de suite.

### Garanties

| Exigence | Mise en œuvre |
|---|---|
| Idempotence | `op_id` unique par opération ; `entity_id` généré sur l'appareil. Une opération renvoyée après une coupure est reconnue par le serveur (`duplicate`). |
| Reprise après erreur, coupure, fermeture ou redémarrage | La file est sur disque. Une opération restée `SENDING` est remise en `PENDING` au lancement et après chaque échec. |
| Transactions | Écriture locale et ajout à la file dans une seule transaction. Chaque lot de résultats est appliqué dans une transaction. Chaque page reçue est appliquée **avec** son curseur, dans une même transaction. |
| Dépendances | `depends_on` est calculé automatiquement quand l'élément, ou son parent, a une création encore dans la file. Un enfant dont le parent est refusé est bloqué (refusé) au lieu d'être différé sans fin. |
| Conflits | Fusion champ par champ côté serveur, avec `base` et `base_version` (seuls les champs modifiés partent). Au pull, un élément ayant une opération locale en attente n'est pas écrasé (rebase) : le résultat de l'envoi apporte l'état à jour. |
| Exécution unique | Deux déclenchements simultanés partagent la même exécution. |
| Session | Sur `401`, le jeton est renouvelé une seule fois pour toutes les requêtes en cours (le jeton de renouvellement change à chaque usage), puis la requête repart. Si le renouvellement est refusé, l'écran de connexion s'affiche et **la file est conservée**. Hors ligne, la session reste valable. |
| Appareil révoqué | `DEVICE_REVOKED` efface toutes les données locales. |
| Purge | Les opérations terminées sont supprimées après 30 jours. |

### Déclenchement

- à l'ouverture de la session ;
- au retour du réseau ;
- au retour dans l'application ;
- 3 s après une saisie (les saisies rapprochées sont regroupées) ;
- toutes les 5 min ;
- en tirant la liste vers le bas, ou avec « Synchroniser maintenant ».

## 5. Indicateur visible (cahier des charges)

| Pastille | Condition |
|---|---|
| 🟢 **Synchronisé** | Réseau disponible, rien en attente |
| 🟠 **Synchronisation en cours** | Envoi ou récupération en cours, modifications en attente, ou nouvel essai prévu |
| 🔴 **Hors connexion** (ou **Session expirée**) | Pas de réseau, serveur injoignable, ou reconnexion nécessaire |

- La pastille associe toujours une couleur et un texte, avec un compteur : modifications en attente, ou éléments à traiter (en rouge).
- Elle ouvre le **centre de synchronisation** : dernière synchronisation réussie, « Synchroniser maintenant », éléments à traiter, éléments en attente avec leur motif.
- Hors connexion, un bandeau rassurant s'affiche : les données restent consultables et saisissables.

## 6. Sécurité

- **Aucun secret dans le code.** L'adresse de l'API est fournie à la compilation (`--dart-define=VSH_API_BASE_URL=…`). Sans elle, l'application affiche « non configurée ».
- **HTTPS obligatoire.** Le HTTP en clair n'est accepté que pour une adresse locale de développement, et Android ne l'autorise que dans la version de débogage (`src/debug/AndroidManifest.xml`).
- **Base chiffrée** (SQLite3MultipleCiphers, clé aléatoire de 256 bits). L'application refuse de démarrer si le chiffrement est absent. Si la clé est perdue, la base illisible est recréée, puis les données reviennent du serveur.
- **Stockage sécurisé** (Keystore Android, Keychain iOS) pour le jeton de renouvellement, l'identifiant d'appareil, la clé de base et le profil minimal. Le jeton d'accès reste en mémoire ; le mot de passe n'est jamais enregistré.
- **Sauvegarde Android désactivée** (`allowBackup="false"`).
- **Appareil partagé** : si un autre compte se connecte, les données du précédent utilisateur sont effacées.
- **Minimisation (D-007)** : seules les données du périmètre de l'utilisateur arrivent sur le téléphone, et le serveur revérifie chaque opération (droits, validations, audit).
- **Aucune donnée médicale dans les chemins de navigation** (identifiants UUID) ni dans les messages d'erreur.

## 7. Lancer, compiler, tester

```bash
cd vsh_mobile
flutter pub get
dart run build_runner build --delete-conflicting-outputs   # après une modification des tables

# Émulateur Android et serveur local XAMPP :
flutter run --dart-define=VSH_API_BASE_URL=http://10.0.2.2:8085/vsh_niger/vsh_serveur/public/api/v1

# Production :
flutter build appbundle --release --dart-define=VSH_API_BASE_URL=https://<domaine>/api/v1 --dart-define=VSH_APP_VERSION=1.0.0

flutter analyze
flutter test
```

**Scénarios testés** (`test/sync_engine_test.dart`, sur un serveur simulé conforme au contrat) :

- Internet disponible ;
- Internet coupé, puis revenu ;
- coupure pendant l'opération : la réponse est perdue, le renvoi ne crée pas de doublon ;
- double synchronisation simultanée ;
- erreur serveur, avec attente progressive ;
- serveur indisponible (5xx) ;
- jeton expiré ;
- appareil révoqué ;
- conflit sur des champs différents (fusion) et sur un même champ ;
- rebase au pull ;
- refus et abandon ;
- dépendances, et parent refusé ;
- récupération par pages, avec reprise exacte après une coupure ;
- application fermée pendant l'envoi ;
- téléphone redémarré (base sur disque) ;
- purge.

**Autres tests** :

- `test/patient_repository_test.dart` : écriture transactionnelle, champs modifiés avec `base`, recherche, **chiffrement** (fichier illisible sans la clé, refus d'une mauvaise clé).
- `test/server_integration_test.dart` : contrat réel avec un serveur de **développement**. Il couvre la connexion avec enregistrement de l'appareil, la création (n° de dossier attribué), la modification, le renvoi d'un `op_id` déjà traité et le renouvellement du jeton. Il n'est lancé que si `VSH_IT_BASE_URL`, `VSH_IT_PHONE` et `VSH_IT_PASSWORD` sont définis.

## 8. Tournée à domicile hors ligne (étape 11)

La navigation du bas propose deux entrées : **Tournée** et **Patients**. Le code est dans `lib/homecare/`.

### Écrans

| Écran | Contenu |
|---|---|
| Tournée | Visites en cours de l'équipe, file d'attente (urgentes en tête), visites closes depuis 24 h. Une visite prise par une autre équipe disparaît de la file. |
| Fiche de visite | Patient, motif, adresse et repère, bouton **Appeler**, carte OpenStreetMap (domicile, précision, position de l'équipe), distance à vol d'oiseau, **Itinéraire** (application de navigation du téléphone), historique avec les étapes pas encore envoyées, consultation à domicile (constantes, notes) |
| Barre d'action (en bas, à portée de pouce) | Une action principale selon l'étape : *Prendre en charge*, *Partir vers le domicile*, *Je suis arrivé*, *Commencer les soins*, *Terminer la visite*. Actions secondaires : *Se désister*, *Visite impossible* (motif obligatoire) |

- Les boutons suivent les **droits** de l'utilisateur :
  - `homecare.intervene` pour les étapes ;
  - `vitals.record` pour les constantes ;
  - `patients.medical.write` pour les notes (D-010).
- Le serveur revérifie tout.
- Les étapes proposées reprennent la machine à états du serveur (`HomecareService::FIELD_ACTIONS`), sans règle ajoutée.

### Hors ligne

La tournée complète se fait sans réseau :

```text
accept → depart (GPS) → trajet → arrive (GPS) → start (consultation_id généré) → constantes, notes → complete
```

- **Affichage immédiat.** Chaque étape met à jour la fiche sur le téléphone, avec la mention « pas encore envoyé ». L'état du serveur la remplace après l'envoi.
- **Enchaînement des étapes.** Chaque étape dépend de la précédente (`depends_on`) : si l'une est refusée (par exemple visite prise par une autre équipe, ou annulée par la clinique), les suivantes ne sont pas appliquées. Les points de trajet ne font pas partie de la chaîne : un point refusé ne bloque aucune étape.
- **Consultation créée hors ligne.** Au démarrage, la consultation `DOMICILE` reçoit un identifiant généré sur le téléphone. Les constantes et les notes en dépendent et partent après le démarrage.
- **Abandon d'une étape refusée.** Il retire aussi les étapes suivantes de la même visite, puis la visite reprend le dernier état connu du serveur, ou à défaut l'état d'avant la saisie.
- **État du serveur mis de côté.** Tant qu'une modification locale est en attente sur un élément, l'état reçu du serveur est conservé à part (table `server_shadows`, schéma v2). Il est appliqué si la modification est abandonnée, et remplacé par le résultat si elle est acceptée.
- **Heure du serveur.** Les étapes sont horodatées à l'heure de l'action, corrigée de l'écart d'horloge mesuré à chaque récupération (`server_time`). Un téléphone en avance ne fait donc pas refuser les étapes : le serveur n'accepte pas une heure future de plus de 5 min.

### GPS

- La position est jointe au départ et à l'arrivée : meilleure position en 12 s au plus, affinée pendant la mesure. La position (0, 0) est écartée.
- **Le GPS ne bloque jamais une étape** : sans position, l'étape part sans elle et un message l'indique.
- Une arrivée à plus de 300 m du domicile (`geo.arrival_radius_m`), marge d'imprécision déduite, est signalée sans être bloquée.
- **Trajet** :
  - enregistré seulement pendant une visite en cours (en route, sur place, soins), application ouverte, un point toutes les 30 s au plus ;
  - envoyé par lots de 100 points au plus, sans doublon côté serveur ;
  - les points en attente sont écrits avant chaque étape, pour respecter l'ordre.
- **Vie privée du personnel** : aucune position en dehors d'une visite, et pas d'autorisation de localisation en arrière-plan.
- **Carte** : fonds OpenStreetMap, mis en cache sur le téléphone. Sans réseau, les repères, la distance et les coordonnées restent affichés.

### Constantes

La saisie accepte la virgule décimale. Les bornes de plausibilité sont celles du serveur (`VitalSignService::MEASURES`), sans aucune interprétation clinique. Au moins une mesure est obligatoire.

### Tests

- `test/homecare_repository_test.dart` (serveur simulé) :
  - tournée complète hors ligne, puis envoi dans l'ordre ;
  - visite prise entre-temps par une autre équipe : refus, abandon en cascade et état du serveur appliqué ;
  - retour à l'état d'avant l'action ;
  - horodatage corrigé ;
  - contrôles locaux.
- `test/server_integration_test.dart`, sur le **serveur réel**, avec `VSH_IT_NURSE_PHONE` et `VSH_IT_PATIENT_ID` :
  - la régulation crée la demande ;
  - l'infirmière de l'équipe mobile fait toute la tournée hors ligne (prise en charge, départ GPS, trajet, arrivée, démarrage, constantes, fin) ;
  - une seule synchronisation applique les 7 opérations ;
  - la fiche du serveur est `TERMINEE`, avec sa consultation et son arrivée géolocalisée.
- `test/screens_golden_test.dart` : rendu des écrans Tournée, Visite et Synchronisation à 390 × 844 (images dans `test/goldens/`).

## 9. Soins, agenda, notifications, référentiels et dossiers (étape 12)

La navigation du bas compte quatre entrées : **Tournée**, **Soins**, **Agenda** et **Patients**. La cloche des notifications, avec le nombre de non lues, est dans la barre supérieure.

| Écran | Contenu | Opérations hors ligne |
|---|---|---|
| Soins | À faire aujourd'hui (les soins en retard sont signalés), à venir, réalisés depuis 24 h. **Soin fait** (confirmation : un soin réalisé est définitif et facturé), avec des observations facultatives pour les droits médicaux. **Annuler** avec motif | `treatment` ACTION `perform` (`performed_at` à l'heure du serveur) et `cancel` |
| Visite en cours | **Soin réalisé** : type choisi dans le référentiel, rattaché à la consultation à domicile. Il dépend du démarrage s'il a été fait hors ligne | `treatment` CREATE `REALISE` |
| Agenda | Rendez-vous du jour, avec navigation d'un jour à l'autre. **Arrivé** et **Absent**, pour les rendez-vous confirmés ou déplacés du jour (`appointments.manage`) | `appointment` ACTION `check_in`, `no_show` |
| Notifications | Liste, **Tout marquer lu**. Ouvrir une notification mène à l'écran concerné (visite, dossier, agenda, soins) | `notification` UPDATE `read_at` |
| Patients | **Chercher aussi sur le serveur** (en ligne). **Garder sur le téléphone** épingle le dossier, qui arrive aussitôt avec ses données autorisées | `POST /patients/search` (nom dans le corps, jamais dans l'URL), `POST /sync/patients/{id}/pin` |
| Fiche patient | Visites, rendez-vous à venir, soins, dernières constantes : données présentes sur le téléphone, selon les droits (D-010) | — |

### Référentiels et paramètres hors ligne

- `GET /reference/bundle` est appelé après chaque synchronisation réussie : un lot complet la première fois, puis seulement les changements (`since`). Il est gardé dans `sync_meta`.
- Il contient les types de soins, les actes, les examens, les médicaments, les tarifs, les services et les paramètres de la clinique.
- L'application lit ses seuils dans ces paramètres, jamais dans le code :
  - rayon d'arrivée : `geo.arrival_radius_m` ;
  - intervalle du trajet : `geo.track_interval_seconds`.
  Tant qu'aucun lot n'a été reçu, elle applique les valeurs par défaut documentées côté serveur.

### Règles communes

- Toute action met l'affichage à jour tout de suite, garde l'état d'avant pour pouvoir l'annuler, et s'enchaîne sur l'action précédente du même élément (`RecordRepository`).
- **Refus pas encore traité** : tant qu'une modification refusée attend la décision de l'utilisateur, l'état envoyé par le serveur est mis de côté, puis appliqué à l'abandon. Exemple : un soin annulé entre-temps par la clinique retrouve l'état « Annulé », avec le motif de la clinique.
- **Droits** : les boutons suivent les droits de l'utilisateur (`treatments.perform`, `appointments.manage`, `patients.medical.write`, `consultations.update`), et le serveur revérifie tout.

### Hors du périmètre mobile, par choix

- **Espace patient** : il reste le [portail web](17_PORTAIL_PATIENT.md). Il est conçu pour téléphone, se connecte par code SMS (D-001) et ne demande aucune installation. Une application patient Flutter doublerait la maintenance sans nouvel usage. Elle peut être ajoutée plus tard sur la même API (`/me/patients/…`).
- **Saisie des résultats d'examens** : elle reste sur le poste du laboratoire (interface web, validation D-005). L'application affiche les soins et les constantes, pas les résultats.

### Tests

- `test/clinical_repositories_test.dart` (serveur simulé) :
  - soin fait hors ligne ;
  - soin annulé entre-temps : refus, puis état de la clinique ;
  - soin réalisé pendant la visite, dépendant du démarrage ;
  - arrivée et absence à un rendez-vous ;
  - notification lue (une seule opération) ;
  - référentiels : lot complet, lot incrémental, désactivation, paramètres.
- `test/server_integration_test.dart` (serveur réel) : lot de référentiels complet puis incrémental, recherche serveur, épinglage, dossier épinglé reçu à la synchronisation.
- `test/screens_golden_test.dart` : écrans Tournée, Visite, Soins, Agenda et Synchronisation. L'heure est fixe (`appNow`), donc les images ne dépendent pas du moment du test.

## 10. Notifications (étape 13)

Règles serveur et configuration : [21_NOTIFICATIONS.md](21_NOTIFICATIONS.md) (D-011).

| Fichier | Rôle |
|---|---|
| `lib/notifications/notification_alerts.dart` | Repère les notifications arrivées par la synchronisation (non lues, plus récentes que la dernière signalée, repère `notifications.alerted_until` dans `sync_meta`) et déclenche les alertes. Pas d'avalanche à la première synchronisation. |
| `lib/notifications/local_alerts.dart` | Alertes système (flutter_local_notifications, canal `vsh_notifications` partagé avec FCM). Autorisation demandée à la connexion (Android 13 et plus, iOS). Au-delà de 3 arrivées, une seule alerte. Toucher l'alerte ouvre l'écran concerné. Tout est effacé à la déconnexion. |
| `lib/notifications/push_service.dart` | FCM facultatif, configuré à la compilation (`FIREBASE_API_KEY`, `FIREBASE_APP_ID`, `FIREBASE_SENDER_ID`, `FIREBASE_PROJECT_ID` en `--dart-define`). Jeton envoyé au serveur à la connexion et à chaque renouvellement. Un message reçu application ouverte déclenche une synchronisation. Jeton détruit à la déconnexion. |

- Sans Firebase, tout fonctionne : les notifications arrivent à la synchronisation et sont signalées par des alertes locales.
- Push actif et application en arrière-plan : le système affiche le message, et l'alerte locale n'est pas répétée.
- Android : permission `POST_NOTIFICATIONS`, désucrage activé (`isCoreLibraryDesugaringEnabled`, `desugar_jdk_libs` 2.1.4) comme l'exige flutter_local_notifications.
- **Non vérifié sur ce poste** : la compilation Android, car les licences du SDK ne sont pas acceptées. `flutter analyze` et `flutter test` passent (46 tests).

## 11. Espace patient

Une seule application, deux espaces. Après la connexion, le type de compte oriente :

- le **personnel** vers la tournée, les soins, l'agenda et les dossiers ;
- les **patients** vers leur espace (`/p/...`).

Le routeur empêche de passer d'un espace à l'autre.

### Connexion

- **Code SMS** (mode par défaut) : n° de dossier + téléphone, puis code à usage unique (D-001). Le serveur accepte désormais un `device` à `POST /auth/patient-portal/login`. La session est donc liée au téléphone : 30 jours sans nouveau SMS, push possible, appareil visible et révocable.
- **Mot de passe** : personnel, ou patient inscrit avec un mot de passe.

### Écrans (`lib/patient_space/`)

| Onglet | Contenu |
|---|---|
| Accueil | Visite en cours avec ses étapes, prochain rendez-vous, accès aux résultats, ordonnances et factures. Deux actions : « Prendre rendez-vous », « Demander une visite à domicile ». |
| Rendez-vous | À venir (annulables avec motif), historique. Demande : service, jour, créneau libre calculé par le serveur, motif. |
| À domicile | Suivi par étapes, rappel « urgence vitale », annulation. Demande : motif, urgence, téléphone, adresse et repère préremplis depuis le dossier, position GPS en un toucher. |
| Mon dossier | Résultats validés (mention « hors valeurs de référence »), ordonnances et factures, avec PDF ouvert par le lecteur du téléphone. |

- **Famille** (D-009) : pastilles de choix du dossier quand le compte en gère plusieurs.
- **Notifications** : cloche et alertes partagées avec le personnel ; les liens ouvrent l'écran patient concerné.

### Données

- `PatientSpaceRepository` lit `/me/patients`, puis, pour chaque dossier en parallèle, ses rendez-vous, visites, examens, ordonnances et factures, ainsi que les 50 dernières notifications. Le tout est écrit en une transaction dans la base locale chiffrée (entités `me.*`), donc lisible hors ligne.
- Rafraîchissement : à l'ouverture, toutes les 5 minutes, au retour dans l'application, à la réception d'un push, ou en tirant vers le bas.
- **Pas de pull par journal** pour les patients (`SyncEngine.pullEnabled`). Seul l'envoi des opérations locales est fait (notification lue). Raison : [22_MONTEE_EN_CHARGE.md](22_MONTEE_EN_CHARGE.md), où le pull d'un nouvel appareil patient mesure 3,4 s sur un journal de 3 M de lignes.
- Les demandes (rendez-vous, visite, annulation) partent en ligne, car le serveur valide créneaux et règles. Hors ligne, le message l'indique.
- Les PDF sont gardés dans le cache temporaire et effacés à la déconnexion.

### Tests

- `test/patient_space_repository_test.dart` : dossiers de la famille et leurs éléments ; données conservées hors ligne ; élément disparu côté serveur retiré ; seules les 50 dernières notifications gardées ; demandes envoyées puis liste relue.
- `tests/Api/RegistrationTest::testMobileAppPatientLoginIsBoundToTheDevice` : appareil invalide refusé ; session liée à l'appareil, visible dans `/me/devices` ; jeton push accepté.
- Vérifié sur XAMPP avec le patient de démonstration VSH-2026-000002 : code SMS, connexion liée à l'appareil, lecture du dossier, déconnexion.
- Non vérifié : les écrans sur un vrai téléphone (compilation Android impossible sur ce poste, licences du SDK non acceptées). `flutter analyze` ne signale rien et les 50 tests Flutter passent.

## 12. Compte et navigation intégrée

### Menu « Mon compte » (tous les profils)

Icône en haut à droite des écrans principaux :

- **personnel** : tournée, soins, agenda, patients ;
- **patients** : accueil, rendez-vous, visites, dossier.

Le menu affiche le nom et le rôle, le centre de synchronisation (personnel), la version, et **Se déconnecter**. Avant la déconnexion, si des modifications ne sont pas encore envoyées, leur nombre est affiché avec le conseil d'attendre la synchronisation : elles seraient perdues si un autre compte se connectait sur ce téléphone.

### Itinéraire sans quitter l'application (`lib/homecare/navigation_screen.dart`, `routing.dart`)

« Itinéraire vers le domicile », depuis la fiche de visite, ouvre la navigation intégrée :

- **Tracé routier** calculé par un serveur OSRM (données OpenStreetMap), avec distance, durée et heure d'arrivée estimée.
- **Consigne suivante en français** : « Tournez à gauche sur Rue du Grand Marché », « Au rond-point, prenez la 2e sortie ».
- **Position suivie en direct** : la carte suit l'équipe. Si l'utilisateur déplace la carte, un bouton permet de recentrer.
- **Recalcul automatique** au-delà de 60 m d'écart avec le tracé (20 s au plus souvent), ou à la demande.
- **Arrivée** : à moins de 60 m du domicile, le message « Vous êtes arrivé » renvoie à la fiche pour signaler l'arrivée.
- **Sans réseau** : ligne pointillée, direction (« nord-est ») et distance à vol d'oiseau.
- **Écran maintenu allumé** pendant la navigation (`wakelock_plus`).
- Menu : appeler le patient, ou ouvrir une autre application de navigation.
- Le trajet de l'équipe continue d'être enregistré pendant la navigation (TrackRecorder).

Confidentialité : seules les coordonnées de départ et d'arrivée sont envoyées au service d'itinéraire, jamais de nom ni de donnée médicale.

Service : par défaut, le serveur public de la FOSSGIS (`routing.openstreetmap.de/routed-car`). Vérifié depuis le poste : un trajet de 2,0 km dans Niamey, avec les vrais noms de rues. En production, prévoir un serveur OSRM propre (carte du Niger, quelques Go, pas de limite d'usage) : `--dart-define=VSH_ROUTING_URL=https://…`.

Limite : les fonds de carte ne sont gardés qu'en mémoire. Hors réseau, une zone jamais affichée reste grise ; le tracé, la direction et la distance restent affichés.

Tests : `test/routing_test.dart` couvre la lecture d'une réponse OSRM et les consignes en français, la prochaine consigne et la distance restante, la sortie d'itinéraire, le cap et les durées. Les images de référence (`test/goldens`) ont été mises à jour pour l'icône du compte et le bouton d'itinéraire.

## 13. Suite (étape 14)

- Durcissement, rapports, mise en production.
- Côté mobile : trajet en arrière-plan (si la clinique le décide, avec information du personnel), purge locale des dossiers sortis du périmètre, push iOS (clé APNs).
