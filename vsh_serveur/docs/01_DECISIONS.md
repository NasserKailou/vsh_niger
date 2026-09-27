# Journal des décisions

Chaque décision : date, contexte, choix retenu, conséquences. Les décisions remplacent les propositions correspondantes du [rapport initial](00_RAPPORT_INITIAL.md).

---

## D-001 — Accès au portail web patient (2026-09-25)
**Choix** : identification par **n° de dossier + n° de téléphone + code OTP SMS** à usage unique.
**Conséquences** : table `otp_codes` (code haché, expiration courte, tentatives limitées), limitation par IP et par identifiant, message d'erreur identique quel que soit le champ erroné.

## D-002 — Fournisseur SMS (2026-09-25)
**Choix** : passerelle SMS **abstraite** (`SmsGatewayInterface`) avec trois pilotes :
| Pilote | Usage |
|---|---|
| `log` | Développement et tests : le SMS est écrit dans le journal, rien n'est envoyé |
| `twilio` | **Pilote de production par défaut** : API stable et documentée, couverture internationale (+227), accusés de réception |
| `http` | Agrégateur SMS local configurable (URL, méthode, paramètres, en-tête d'authentification) pour passer à un fournisseur nigérien moins coûteux sans modifier le code |

Le pilote et ses identifiants sont définis **uniquement dans `.env`** (`SMS_DRIVER`, `SMS_*`), jamais en base ni dans le code.
**À vérifier avant la mise en production** : l'enregistrement d'un identifiant d'expéditeur (sender ID) auprès des opérateurs du Niger et le coût unitaire réel.
Les SMS ne contiennent jamais de donnée médicale (ex. « Votre code Vision Homecare : 482913 »).

## D-003 — Dispatch des demandes à domicile (2026-09-25)
**Choix** : **les deux modes coexistent**. Les équipes autorisées peuvent accepter une demande `EN_ATTENTE` (auto-attribution) et le régulateur (permission `homecare.dispatch`) peut affecter ou réaffecter une équipe.
**Règle** : une seule équipe active par demande. Le serveur fait l'acceptation par mise à jour conditionnelle (`WHERE status = 'EN_ATTENTE'`) : en cas d'acceptation simultanée, la première gagne et les autres reçoivent `INVALID_TRANSITION`.
Paramètre `homecare.dispatch_mode` (`BOTH` par défaut, `SELF_ASSIGN` ou `DISPATCH_ONLY` possibles).

## D-004 — Paiements hors plateforme (2026-09-25)
**Choix** : **les paiements ne sont pas gérés dans l'application.** La clinique émet la facture, et le patient la consulte ou la télécharge (PDF) dans l'application ou sur le portail web.
**Conséquences** :
- Pas de tables `payments` ni `payment_methods`, pas d'intégration mobile money, pas de rôle CAISSIER.
- Statuts de facture : `BROUILLON → EMISE → ANNULEE`.
- Pour garder le suivi des impayés au dashboard, un utilisateur habilité peut **déclarer** l'état de règlement de la facture (`NON_REGLEE`, `PARTIELLEMENT_REGLEE`, `REGLEE`), avec le montant déclaré et la date. Il s'agit d'une simple information : aucune transaction n'est enregistrée ni traitée, et chaque changement est historisé et audité.
- Notification au patient à l'émission de la facture (sans montant ni détail médical dans le push/SMS).

## D-005 — Validation des résultats d'examens (2026-09-25)
**Choix** : un résultat peut être validé par **le médecin traitant du patient** ou par **un membre de l'équipe en charge** de la prise en charge concernée.
**Règle serveur** (Policy `ExaminationPolicy::canValidate`), cumulative avec la permission `examinations.validate` :
1. l'utilisateur est le médecin traitant du patient (`patients.attending_physician_id`), **ou**
2. l'utilisateur est le prescripteur de l'examen, **ou**
3. l'utilisateur est membre (à la date de validation) de l'équipe affectée à l'intervention à domicile liée à la consultation d'origine.

Le technicien qui a saisi le résultat ne peut pas valider son propre résultat (séparation des tâches).
**Conséquence** : ajout de `patients.attending_physician_id` (médecin traitant, modifiable et audité).

## D-006 — Version PHP (2026-09-25)
**Choix** : **PHP ≥ 7.3**. Le code utilise uniquement la syntaxe 7.3, et les tests tournent sur 7.3 et sur 8.x (l'environnement local est en 8.2).
Composer : `"require": {"php": ">=7.3"}` et `config.platform.php = 7.3.0`.
L'hébergement de production n'est pas encore choisi : un VPS reste recommandé (cron, HTTPS, sauvegardes).

## D-007 — Autres points du rapport (§L) (2026-09-25)
Sans réponse explicite, les **propositions par défaut** sont retenues :
- Patient non validé : pas de demande de RDV ni de visite à domicile ; l'accueil peut la créer pour lui.
- Périmètre hors ligne des professionnels : patients de l'équipe, ou vus dans les 6 derniers mois (paramètre `sync.offline_scope_months`), plus recherche en ligne.
- Langue : français en V1, architecture prête pour la traduction.
- Assurances et pharmacie/stock : hors périmètre V1.
- Racine du projet : `vsh_niger/` = `VISION_HOMECARE/`.

## D-009 — Un compte patient peut gérer plusieurs dossiers (2026-09-25)
**Contexte** : il est courant qu'un parent utilise le même téléphone pour toute la famille, alors que le numéro de téléphone identifie un compte de façon unique.
**Choix** : un compte patient (un numéro) peut être rattaché à plusieurs dossiers : le sien, ceux de ses enfants ou de proches. Chaque dossier est validé séparément par l'accueil. Le portail web et l'application affichent les dossiers du compte.
**Conséquence** : migration 0015 (`patients.user_id` n'est plus unique).
**Réversible** : pour revenir à « un compte = un dossier », il suffit d'interdire l'ajout de proches (`POST /me/patients`).

## D-010 — Accès du personnel aux données médicales (2026-09-25)
**Choix (provisoire)** : les données médicales sont accessibles au personnel qui détient `patients.medical.read` (médecins et infirmiers par défaut). **Chaque consultation est tracée** dans le journal d'audit. L'accueil et les techniciens n'y ont pas accès.
**Évolution prévue** : restreindre selon la relation de soin (médecin traitant, équipe affectée, consultation ou intervention en cours) dès que les modules Consultations et Homecare existeront, avec un accès d'urgence tracé et justifié.

## D-008 — Compatibilité du schéma (2026-09-25)
Cible : **MariaDB ≥ 10.4** (environnement actuel) et MySQL ≥ 8.0.16.
Les contraintes `CHECK` sont appliquées par ces versions. Sur une version plus ancienne, elles seraient ignorées sans erreur, et la validation serveur reste de toute façon la référence.
Chaque connexion de l'API impose `sql_mode` strict (`STRICT_ALL_TABLES`, `NO_ZERO_DATE`, `ERROR_FOR_DIVISION_BY_ZERO`) et `time_zone = '+00:00'`.

## D-011 — Envoi des notifications par push et SMS (2026-09-27)
**Contexte** : la notification in-app existe depuis l'étape 3 et fonctionne sans push (rapport initial §13). Il restait à prévenir les utilisateurs hors de l'application, à un coût maîtrisé pour les SMS.
**Choix** :
- **Boîte d'envoi transactionnelle** (`notification_deliveries`) : les envois push et SMS sont enregistrés dans la même transaction que la notification, puis traités par `php bin/console.php notifications:dispatch` (cron chaque minute). Pas d'appel réseau dans une transaction, rien n'est envoyé si l'action est annulée, et les envois sont retentés puis abandonnés après 5 échecs.
- **Push FCM HTTP v1** sans dépendance (pilotes `none`, `log`, `fcm` ; compte de service dans `.env`), activable par l'administrateur (`notifications.push_enabled`). Jeton lié à l'appareil de la session, supprimé à la déconnexion, ignoré pour un appareil révoqué.
- **SMS aux patients uniquement**, en **repli par défaut** (`notifications.sms_mode = FALLBACK`) : envoyé seulement si le push n'a pas abouti. Types choisis par l'administrateur (`notifications.sms_types`). Le personnel n'en reçoit pas.
- Une notification lue ou vieille de plus de 12 h n'est plus envoyée.
**Conséquences** : migration 0017, deux paramètres, `GET /notification-deliveries` pour la supervision. Côté mobile, alertes locales sans push, et FCM facultatif configuré à la compilation. Détail : [21_NOTIFICATIONS.md](21_NOTIFICATIONS.md).
**À décider avant la production** : création du projet Firebase de la clinique, clé APNs pour iOS, et liste définitive des types envoyés par SMS au regard du coût unitaire (D-002).
