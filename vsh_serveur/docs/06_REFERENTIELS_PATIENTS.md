# Référentiels, tarifs, paramètres et dossier patient

## 1. Référentiels

Services, actes médicaux, types de soins, types d'examens et médicaments sont décrits dans `src/Modules/Reference/ReferenceCatalog.php`. Un seul service générique les gère : **ajouter un référentiel = ajouter une entrée au catalogue**.

| Ressource | Sous-ressource | Champs principaux |
|---|---|---|
| `/services` | `/services/{id}/schedules` (plages horaires : jour 1-7, début, fin, durée d'un créneau, capacité) | code, label, accepts_appointments |
| `/medical-acts` | — | code, label, category |
| `/treatment-types` | — | code, label, description |
| `/examination-types` | `/examination-types/{id}/parameters` (unité, valeurs de référence, sexe et âge, valeurs possibles) | code, label, category, sample_type |
| `/medications` | — | dci, commercial_name, form, strength, route |

- **Routes** : `GET` et `POST /{ressource}`, `GET` et `PUT /{ressource}/{id}`, et pour les sous-ressources `GET`/`POST /{ressource}/{id}/{sous-ressource}` et `PUT …/{idSousRessource}`.
- **Droits** : lecture avec `reference.read`, écriture avec `reference.manage`.
- **Recherche et filtres** : `?search=…&active=1&page=&per_page=` (200 éléments au plus par page).
- **Pas de suppression** : on désactive un élément (`"active": false`). L'historique des consultations et des factures reste ainsi cohérent.
- **Normes cliniques** : les valeurs de référence des examens sont des données saisies par la clinique. Aucune norme n'est codée en dur.

## 2. Tarifs (aucun prix dans le code)

| Méthode | Route | Droit |
|---|---|---|
| GET | `/tariffs?billable_type=&billable_id=` | `reference.read` |
| GET | `/tariffs/current?billable_type=&billable_id=&date=` | `reference.read` |
| POST | `/tariffs` | `tariffs.manage` |
| PUT | `/tariffs/{id}` | `tariffs.manage` |

- `billable_type` vaut `MEDICAL_ACT`, `TREATMENT_TYPE`, `EXAMINATION_TYPE` ou `MEDICATION`. `billable_id` est l'identifiant de l'élément.
- Les dates sont des **dates locales de la clinique** (fuseau du paramètre `app.timezone`). Un tarif sans `valid_to` n'a pas de fin.
- Un nouveau tarif ne peut pas commencer dans le passé. Le tarif en cours sans date de fin est **clôturé automatiquement la veille**.
- Deux périodes d'un même élément ne se chevauchent jamais (`409 TARIFF_OVERLAP`).
- Un tarif **en vigueur** ne change plus de montant (`409 TARIFF_IN_EFFECT`) : on ne peut que fixer sa date de fin. Un tarif **échu** est figé. L'historique des prix reste donc exact. De plus, les factures copieront le prix au moment de l'acte.
- Statut calculé : `FUTURE`, `CURRENT` ou `PAST`.

## 3. Paramètres

`GET /settings` et `PUT /settings` (droit `settings.manage`). Corps de la modification :

```json
{ "values": { "homecare.dispatch_mode": "SELF_ASSIGN", "appointments.max_days_ahead": 30 } }
```

Chaque valeur est vérifiée selon son type (BOOL, INT, JSON, texte) et, le cas échéant, selon la liste des valeurs autorisées. Les modifications sont auditées. Les paramètres marqués publics sont transmis aux applications.

## 4. Paquet de référence pour le mobile (hors ligne)

`GET /reference/bundle` renvoie tout le référentiel. `GET /reference/bundle?since=<generated_at précédent>` ne renvoie que ce qui a changé depuis.

- Personnel : paramètres publics, services (avec plages), actes, soins, examens (avec paramètres), médicaments, tarifs en cours et à venir.
- Compte patient : paramètres publics et services uniquement.
- Les éléments désactivés sont transmis avec `active: false`. Modifier une sous-ressource (plage, paramètre) remet son parent dans le paquet suivant.

## 5. Dossier patient (personnel)

| Méthode | Route | Droit |
|---|---|---|
| GET | `/patients?status=&page=` | `patients.read` |
| POST | `/patients/search` — `{q, file_number, phone, birth_date, status}` | `patients.read` |
| POST | `/patients` | `patients.create` |
| GET / PUT | `/patients/{id}` | `patients.read` / `patients.update` |
| PUT | `/patients/{id}/attending-physician` | `patients.assign_attending` |
| GET / PUT | `/patients/{id}/medical-profile` (groupe sanguin, observations) | médical (voir §7) |
| GET / POST / PUT / DELETE | `/patients/{id}/contacts`, `/addresses` | `patients.read` / `patients.update` |
| GET / POST / PUT / DELETE | `/patients/{id}/allergies`, `/medical-history`, `/current-treatments` | médical (voir §7) |
| POST | `/patients/{id}/merge` — `{target_id}` | `patients.merge` |

- **Création par le personnel** : le dossier est validé d'emblée et reçoit son numéro (`VSH-2026-000123`, séquence annuelle sans doublon, préfixe réglable avec `patients.file_number_prefix`). Contacts et adresses peuvent être envoyés avec la création. Un `id` (UUID) fourni par l'appareil est accepté, ce qui préparera la création hors ligne.
- **Doublons** : un dossier existant avec les mêmes nom, prénom et date de naissance, ou le même téléphone et le même prénom, provoque `409 POSSIBLE_DUPLICATE` avec la liste des dossiers semblables. Si ce n'est pas la même personne, on renvoie la requête avec `"confirm_not_duplicate": true`.
- **Recherche** : elle passe par `POST` pour que les noms et téléphones n'apparaissent jamais dans les URL (journaux des serveurs). Chaque mot saisi doit correspondre au début du prénom ou du nom, sans tenir compte de la casse ni des accents.
- **Modifications concurrentes** : envoyer `"version"` (reçue à la lecture). Si le dossier a changé entre-temps, la réponse est `409 VERSION_CONFLICT`.
- **Adresses** : latitude et longitude vont toujours ensemble. Une seule adresse peut être principale.
- **Suppressions** : elles sont logiques (`deleted_at`) et transmises aux appareils par le journal de synchronisation.
- **Médecin traitant** : il doit s'agir d'un médecin actif (profil professionnel `MEDECIN`). Il est utilisé pour la validation des examens (D-005).
- **Fusion** : toutes les données du dossier en doublon sont rattachées au dossier conservé, y compris dans les tables des modules futurs (détection automatique des colonnes `patient_id`). Le doublon passe à l'état `MERGED` et n'est plus modifiable.

## 6. Parcours patient

| Méthode | Route | Rôle |
|---|---|---|
| POST | `/auth/register/code` — `{phone}` | Envoie le code SMS d'inscription |
| POST | `/auth/register` | Crée le compte et le dossier (en attente), et ouvre la session |
| GET / POST | `/me/patients` | Dossiers du compte / ajout d'un proche (D-009) |
| GET | `/me/patients/{id}` | Dossier complet, données médicales comprises, une fois validé |
| GET | `/patient-registrations` | Inscriptions en attente, avec les doublons possibles (`patients.validate_registration`) |
| POST | `/patient-registrations/{id}/approve` | Numéro de dossier attribué, compte activé, patient notifié |
| POST | `/patient-registrations/{id}/reject` — `{reason}` | Dossier refusé, patient notifié, compte fermé s'il ne gère plus aucun dossier ouvert |
| POST | `/auth/patient-portal/code` — `{file_number, phone}` | Portail web : envoie le code SMS (réponse identique si les informations sont fausses) |
| POST | `/auth/patient-portal/login` — `{file_number, phone, code}` | Portail web : ouvre la session |
| GET | `/notifications`, `POST /notifications/{id}/read`, `POST /notifications/read-all` | Notifications in-app |

**Inscription (C1)**
- Le code SMS est obligatoire (paramètre `auth.registration_otp_required`).
- Qu'un numéro possède déjà un compte n'est révélé qu'**après** vérification du code, donc uniquement au détenteur du téléphone.
- Un patient en attente peut se connecter et consulter son dossier, mais n'a accès à aucune autre fonction.

**Portail web (D-001)**
- L'identification se fait par n° de dossier, téléphone et code SMS. Le téléphone doit être celui du dossier ou du compte rattaché.
- Un dossier créé à la clinique reçoit à cette occasion un compte patient sans mot de passe.
- Les tentatives sont limitées par n° de dossier et par adresse IP.

**Familles (D-009)**
- Un compte, c'est-à-dire un numéro de téléphone, peut gérer plusieurs dossiers : ceux d'un parent et de ses enfants par exemple.
- Chaque dossier est validé séparément par l'accueil.

## 7. Données médicales (D-010)

- Allergies, antécédents, traitements en cours et profil médical ne sont visibles qu'avec `patients.medical.read` (ou `read_all`), et modifiables avec `patients.medical.write`.
- L'accueil voit l'identité et les coordonnées, mais **pas** les données médicales : la section `medical` est absente de ses réponses.
- **Chaque consultation** de données médicales par le personnel est tracée (`MEDICAL_RECORD_VIEWED`).
- Un patient voit les données médicales de ses propres dossiers validés.
- La restriction selon la relation de soin (équipe, consultation en cours) sera ajoutée avec les modules Consultations et Homecare.

## 8. Tests

- `tests/Api/ReferenceTest.php` : référentiels, plages, paramètres d'examen, tarifs, paquet mobile, paramètres.
- `tests/Api/PatientsTest.php` : création, numérotation, doublons, recherche, versions, droits médicaux, audit, adresses, suppression, médecin traitant, fusion.
- `tests/Api/RegistrationTest.php` : inscription par SMS, validation, refus, notifications, familles, cloisonnement entre patients, portail web.
