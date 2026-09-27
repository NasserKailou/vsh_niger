# Synchronisation Offline/Online — contrat serveur

Ce document décrit le fonctionnement côté serveur et le **contrat que l'application mobile doit respecter**. Pour l'architecture générale, voir le [rapport initial §E](00_RAPPORT_INITIAL.md#e-architecture-offline-first).

## 1. Points d'accès (`/api/v1/sync`, authentification requise)

| Méthode | Route | Rôle | Droit |
|---|---|---|---|
| POST | `/sync/push` | Envoi d'un lot d'opérations faites sur l'appareil | session mobile (appareil enregistré) |
| GET | `/sync/pull?cursor=&limit=` | Changements du serveur depuis le curseur | — |
| GET | `/sync/status` | Heure serveur, dernier curseur, conflits ouverts, entités prises en charge | — |
| GET | `/sync/conflicts?status=` | Conflits de l'utilisateur (`?all=1` pour tous, avec `sync.supervise`) | — |
| POST | `/sync/conflicts/{id}/resolve` | Arbitrage d'un conflit | propriétaire ou `sync.supervise` |
| GET | `/sync/patients` | Dossiers épinglés pour le hors ligne | `patients.read` |
| POST / DELETE | `/sync/patients/{id}/pin` | Épingler / retirer un dossier | `patients.read` |
| GET | `/sync/devices` | Appareils, dernière synchronisation, rejets, conflits | `sync.supervise` |
| GET | `/sync/operations?status=&device_id=` | Journal des opérations reçues | `sync.supervise` |
| POST | `/sync/devices/{id}/revoke` | Révocation d'un appareil perdu ou volé | `sync.supervise` |

## 2. Envoi (PUSH)

```json
POST /api/v1/sync/push
{
  "operations": [
    {
      "op_id": "4f1c…",                        // UUID unique de l'opération (clé d'idempotence)
      "entity": "patient",                     // voir §5
      "entity_id": "9b2e…",                    // UUID de l'élément, généré par l'appareil à la création
      "operation": "CREATE",                   // CREATE | UPDATE | DELETE
      "payload": { "first_name": "Aïcha", "last_name": "Moussa", "sex": "F" },
      "client_created_at": "2026-09-25T08:14:00Z"
    },
    {
      "op_id": "77aa…",
      "entity": "allergy",
      "entity_id": "c0de…",
      "operation": "CREATE",
      "payload": { "patient_id": "9b2e…", "allergen": "Pénicilline" },
      "depends_on": "4f1c…"                    // appliquée seulement si l'opération 4f1c… l'a été
    },
    {
      "op_id": "a1b2…",
      "entity": "patient",
      "entity_id": "5e5e…",
      "operation": "UPDATE",
      "base_version": 3,                       // version connue de l'appareil au moment de la modification
      "base": { "phone": "+22790000000" },     // valeur AVANT modification, pour chaque champ modifié
      "payload": { "phone": "+22796000000" }   // uniquement les champs modifiés
    }
  ]
}
```

Chaque opération reçoit un résultat, dans le même ordre :

| `status` | Signification | Action de l'application |
|---|---|---|
| `APPLIED` | Appliquée. `data` contient l'état serveur (version, n° de dossier attribué…) | Mettre à jour la ligne locale et retirer l'opération de la file |
| `CONFLICT` | Appliquée en partie : un champ a aussi été modifié sur le serveur avec une autre valeur. `data` contient l'état serveur (valeur du serveur conservée) et `conflict` le détail | Afficher le conflit à l'utilisateur, retirer l'opération de la file |
| `REJECTED` | Refusée définitivement (`error.code` : `VALIDATION_ERROR`, `FORBIDDEN`, `NOT_FOUND`, `UNSUPPORTED_OPERATION`…) | Placer dans « éléments à corriger ». Ne jamais supprimer silencieusement |
| `DEFERRED` | L'opération dont elle dépend n'est pas appliquée | La garder dans la file, la renvoyer plus tard |
| `ERROR` | Erreur technique temporaire, rien n'a été enregistré | Renvoyer plus tard (attente progressive) |

`duplicate: true` indique une opération déjà reçue : le résultat enregistré est renvoyé sans nouvelle exécution.

### Garanties

- **Idempotence** : `(appareil, op_id)` est unique en base. Renvoyer un lot après une coupure ne crée jamais de doublon. Une création envoyée deux fois avec deux `op_id` différents est également sans effet, grâce à l'`entity_id` généré par l'appareil.
- **Isolation** : une transaction par opération. Un refus n'annule pas les autres opérations du lot.
- **Mêmes règles qu'en ligne** : chaque opération passe par les services métier. Validations, droits (y compris les droits médicaux), audit et journal sont identiques à ceux de l'API en ligne.
- **Taille** : 50 opérations au plus par lot, 2 Mo au plus par requête.
- **Création de patient hors ligne** : le numéro de dossier est attribué par le serveur à la réception. Un doublon possible ne bloque pas l'envoi : le dossier est marqué `possible_duplicate_of` pour l'accueil.

## 3. Fusion et conflits (UPDATE)

Pour chaque champ de `payload` :

1. Si `base_version` est égale à la version actuelle du serveur, le champ est appliqué.
2. Sinon, si la valeur serveur est déjà égale à la nouvelle valeur, il n'y a rien à faire.
3. Sinon, si la valeur serveur est égale à `base[champ]` (personne ne l'a modifiée entre-temps), le champ est appliqué.
4. Sinon, il y a **conflit** : la valeur du serveur est conservée et la valeur de l'appareil est enregistrée dans `sync_conflicts`.

Deux utilisateurs qui modifient des champs **différents** ne sont donc jamais en conflit.

**Arbitrage** : `POST /sync/conflicts/{id}/resolve` avec l'un des corps suivants :
- `{"resolution": "SERVER"}` pour garder la valeur du serveur ;
- `{"resolution": "CLIENT"}` pour appliquer la valeur de l'appareil ;
- `{"resolution": "MERGE", "values": {"first_name": "…"}}` pour appliquer une valeur choisie.

L'arbitrage repasse par les contrôles de droits et est audité.

## 4. Récupération (PULL)

```
GET /api/v1/sync/pull?cursor=0&limit=200
```

```json
{
  "changes": [
    { "seq": 1042, "entity": "patient", "id": "9b2e…", "operation": "UPSERT", "data": { "…": "…", "version": 4 } },
    { "seq": 1047, "entity": "patient_contact", "id": "3c3c…", "operation": "DELETE", "data": null }
  ],
  "next_cursor": 1047,
  "has_more": false,
  "server_time": "2026-09-25T09:00:00Z"
}
```

- Appeler en boucle tant que `has_more` vaut `true`. **N'enregistrer `next_cursor` qu'après avoir appliqué le lot en base locale**, pour une reprise exacte après une coupure.
- Un élément modifié plusieurs fois n'est transmis qu'une fois, dans son état actuel.
- `UPSERT` : insérer ou remplacer la ligne locale, **sauf si une opération locale est encore en attente sur cet élément** (rebase). `DELETE` : supprimer la ligne locale.
- Les sous-éléments portent `patient_id` (UUID du patient).
- `limit` : 200 par défaut, 500 au plus.

### Périmètre (D-007 : minimisation)

| Utilisateur | Reçoit |
|---|---|
| Patient | Ses propres dossiers et leurs données, ses notifications |
| Personnel | Dossiers dont il est médecin traitant, dossiers qu'il a créés ou complétés durant les 6 derniers mois (`sync.offline_scope_months`), dossiers **épinglés**, ses notifications |

- Les données médicales (allergies, antécédents, traitements, profil médical) ne sont transmises qu'aux utilisateurs autorisés à les lire. L'accueil ne les reçoit pas.
- Épingler un dossier (après une recherche en ligne) le fait entrer dans le périmètre. Le dossier et ses données sont réinscrits dans le journal, donc l'appareil les reçoit au pull suivant, même avec un curseur avancé. Devenir médecin traitant produit le même effet.
- Un dossier qui sort du périmètre n'est pas supprimé à distance : l'application purge localement les dossiers inactifs depuis plus que la durée du périmètre.
- Consultations, soins, examens et ordonnances font entrer le patient dans le périmètre de leur auteur. Le technicien reçoit aussi les dossiers ayant un examen à réaliser. Les membres d'une équipe reçoivent les dossiers des visites à domicile en cours de leur équipe, ainsi que les changements adressés à leurs équipes actuelles (`sync_changes.team_id`).

## 5. Entités synchronisables

| `entity` | Opérations | Remarques |
|---|---|---|
| `patient` | CREATE, UPDATE | Identité. Pas de suppression |
| `patient_contact`, `patient_address` | CREATE, UPDATE, DELETE | `payload.patient_id` obligatoire à la création |
| `allergy`, `medical_history`, `current_treatment` | CREATE, UPDATE, DELETE | Droits médicaux |
| `patient_medical_profile` | CREATE, UPDATE | Un par patient. Un second CREATE met à jour le profil existant |
| `notification` | UPDATE (`read_at`) | Lecture hors ligne |
| `consultation` | CREATE, UPDATE, ACTION `close` / `cancel` | Voir [09_CONSULTATIONS_SOINS.md](09_CONSULTATIONS_SOINS.md) |
| `vital_sign`, `consultation_note` | CREATE | Ajout seulement |
| `consultation_diagnosis` | CREATE, DELETE | Consultation ouverte |
| `treatment` | CREATE, UPDATE, ACTION `perform` / `cancel` | |
| `examination` | CREATE, ACTION `start` / `record_results` / `complete` / `validate` / `cancel` | Voir [10_EXAMENS_ORDONNANCES.md](10_EXAMENS_ORDONNANCES.md). Patient : examens validés seulement |
| `prescription` | CREATE, UPDATE (brouillon), ACTION `sign` / `cancel` | Lignes dans `items`. Patient : ordonnances signées seulement |
| `appointment` | CREATE, ACTION `confirm` / `reschedule` / `cancel` / `check_in` / `no_show` | Voir [13_RENDEZ_VOUS.md](13_RENDEZ_VOUS.md). Planning du praticien affecté |
| `invoice` | lecture seule (pull) | Factures émises. Voir [12_FACTURATION.md](12_FACTURATION.md) |
| `homecare_request` | CREATE, ACTION `approve` / `accept` / `assign` / `release` / `depart` / `arrive` / `start` / `complete` / `fail` / `cancel` / `track` | Voir [11_VISITES_DOMICILE_EQUIPES.md](11_VISITES_DOMICILE_EQUIPES.md). `payload.at` = heure réelle de l'action |

Une opération `ACTION` porte un champ `action` (ex. `"operation": "ACTION", "action": "close"`) et passe par la machine à états du module.

`GET /sync/status` renvoie la liste à jour (`entities`).

**Ajouter une entité à un module** : implémenter `Vsh\Modules\Sync\SyncEntityHandler` en réutilisant les services du module, puis la déclarer dans `src/Modules/<Module>/sync.php`. Chaque écriture métier doit alimenter `ChangeJournal` avec la bonne portée (patient, équipe ou utilisateur).

## 6. Règles pour l'application mobile (étape 10)

1. Écrire la donnée locale **et** l'opération dans la file dans **la même transaction SQLite**.
2. Générer `entity_id` (UUID) à la création locale, et `op_id` (UUID) pour chaque opération.
3. Conserver pour chaque ligne la `version` reçue du serveur. Pour chaque modification, envoyer `base_version` et les valeurs `base`.
4. Renseigner `depends_on` lorsqu'une opération porte sur un élément créé hors ligne et pas encore synchronisé.
5. Pour chaque lot : envoyer d'abord (push), puis récupérer (pull) jusqu'à `has_more = false`.
6. Garder `REJECTED` et `CONFLICT` visibles dans un centre de synchronisation. `DEFERRED` et `ERROR` restent dans la file, avec une attente progressive.
7. Sur `401 TOKEN_EXPIRED` : renouveler la session puis renvoyer. **Ne jamais vider la file.**

## 7. Tests

`tests/Api/SyncTest.php` couvre notamment :
- l'appareil obligatoire, le double envoi sans doublon, la dépendance vers une opération refusée ;
- la mise à jour à jour, la fusion de champs différents, le conflit et son arbitrage ;
- les droits appliqués aux opérations, la double suppression sans effet, les entités non prises en charge ;
- le périmètre et l'épinglage, les droits médicaux au pull, le curseur incrémental ;
- le compte patient (ses dossiers et ses notifications, lecture hors ligne) ;
- la supervision et la révocation d'appareil.
