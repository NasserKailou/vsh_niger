# Base de données

Cible : MariaDB ≥ 10.4 ou MySQL ≥ 8.0.16 (D-008). Le modèle logique et l'ERD sont décrits dans le [rapport initial §F](00_RAPPORT_INITIAL.md#f-base-de-données). Les décisions qui le modifient sont dans le [journal des décisions](01_DECISIONS.md).

## Conventions

| Règle | Détail |
|---|---|
| Moteur / encodage | InnoDB, `utf8mb4_unicode_ci` ; UUID et hachages en `ascii_bin` |
| Identifiants | `id BIGINT` interne (clés étrangères) + `uuid CHAR(36)` unique, **seul identifiant exposé par l'API** et généré par le mobile hors ligne |
| Colonnes de synchronisation | `version` (incrémentée à chaque modification), `created_at`, `updated_at`, `created_by`, `updated_by`, `deleted_at` |
| Dates | `DATETIME` en **UTC** (la connexion impose `time_zone = '+00:00'`) ; affichage en `Africa/Niamey` |
| Montants | `BIGINT UNSIGNED` en FCFA (XOF), sans décimales |
| Statuts | `VARCHAR` + contrainte `CHECK` (plus simple à faire évoluer qu'un `ENUM`) |
| Suppression | Jamais physique pour les données médicales : `deleted_at` ; clés étrangères en `RESTRICT` |
| `created_by` / `updated_by` | Sans clé étrangère (colonnes de traçabilité) ; les acteurs métier (`performed_by`, `prescribed_by`…) en ont une |
| Références polymorphes | `tariffs.billable_id`, `attachments.owner_id` : contrôlées par l'application (pas de FK possible) |
| Journal d'audit | `audit_logs` sans clé étrangère, pour survivre à toute modification |

## Migrations (`database/migrations/`)

Elles sont appliquées dans l'ordre numérique par `php bin/console.php migrate`, et chaque fichier ne s'applique qu'une fois (suivi dans `schema_migrations`, avec une empreinte SHA-256). `migrate:status` signale une migration modifiée après son application. Ne jamais modifier une migration déjà appliquée : ajouter une nouvelle migration.

| Fichier | Tables |
|---|---|
| `0001_identity_access.sql` | users, roles, permissions, role_permissions, user_roles, devices, access_tokens, refresh_tokens, otp_codes, login_attempts |
| `0002_reference_settings.sql` | settings, number_sequences, services, service_schedules, staff_profiles, medical_acts, treatment_types, examination_types, examination_type_parameters, medications, tariffs |
| `0003_teams.sql` | teams, team_members |
| `0004_patients.sql` | patients, patient_medical_profiles, patient_contacts, patient_addresses, medical_history, allergies, patient_current_treatments |
| `0005_appointments.sql` | appointments |
| `0006_homecare.sql` | homecare_requests, homecare_status_history, homecare_interventions, homecare_locations |
| `0007_consultations.sql` | consultations, vital_signs, consultation_diagnoses, consultation_notes |
| `0008_treatments.sql` | treatments |
| `0009_examinations.sql` | examinations, examination_results, attachments |
| `0010_prescriptions.sql` | prescription_templates, prescription_template_items, prescriptions, prescription_items |
| `0011_billing.sql` | invoices, invoice_items, invoice_status_history |
| `0012_notifications_audit.sql` | notifications, push_tokens, audit_logs |
| `0013_sync.sql` | sync_operations, sync_changes, sync_conflicts |
| `0014_access_token_refresh_link.sql` | access_tokens.refresh_token_id (révocation précise des sessions) |
| `0015_patients_family_accounts.sql` | patients.user_id non unique (D-009 : un compte, plusieurs dossiers) |
| `0016_sync_patient_subscriptions.sql` | sync_patient_subscriptions (dossiers épinglés hors ligne), index patients(created_by) |

Total : 56 tables, 108 clés étrangères.

### Règles métier garanties par la base (en plus de la validation serveur)

- Un patient `ACTIVE` a toujours un n° de dossier ; un patient `MERGED` pointe vers le dossier cible.
- Une consultation `DOMICILE` est liée à une demande à domicile ; une consultation `CLOTUREE` a une date de clôture.
- Une demande à domicile au-delà de `EN_ATTENTE` a une équipe affectée ; `ECHEC` exige un motif ; latitude et longitude vont toujours ensemble.
- Un examen `VALIDE` a un validateur, et **le technicien ne peut pas valider son propre résultat** (D-005).
- Un modèle d'ordonnance `ACTIF` a été approuvé ; une ordonnance `SIGNEE` a une date de signature.
- Facture : `net = total − remise` ; une facture émise a un numéro ; l'état de règlement ne se déclare que sur une facture `EMISE` et ne dépasse jamais le net (D-004) ; une ligne vérifie `total = quantité × prix unitaire`.
- Synchronisation : une opération `(appareil, op_id)` est unique (idempotence).

## Données initiales (`database/seeds/`)

| Fichier | Contenu |
|---|---|
| `0001_roles_permissions.sql` | 6 rôles (ADMIN, MEDECIN, INFIRMIER, TECHNICIEN, ACCUEIL, PATIENT), 56 permissions, associations par défaut |
| `0002_settings.sql` | 19 paramètres métier par défaut |

Les seeds peuvent être rejoués : ils n'écrasent jamais une valeur modifiée par l'administrateur. Ils ne contiennent **aucun utilisateur, patient, tarif ni modèle d'ordonnance**. Le premier administrateur est créé par `bin/console.php create-admin` (saisie interactive, étape 3).

## Application

```bash
php bin/console.php migrate
php bin/console.php seed
```

Voir [04_INSTALLATION.md](04_INSTALLATION.md) et [03_SOCLE_BACKEND.md](03_SOCLE_BACKEND.md#console).

## Vérifications effectuées (2026-09-25, MariaDB 10.4.32)

- Les 13 migrations s'appliquent sur une base vide, sans erreur.
- Seeds joués deux fois : mêmes résultats (6 rôles, 56 permissions, 108 associations, 19 paramètres).
- 13 cas invalides rejetés par la contrainte attendue (dossier actif sans numéro, auto-validation d'examen, montants de facture incohérents, règlement sur brouillon, demande en route sans équipe, GPS incomplet, SpO2 > 100, modèle actif non approuvé, opération de sync en double, suppression d'un patient ayant des données liées…) ; 2 cas valides acceptés.
