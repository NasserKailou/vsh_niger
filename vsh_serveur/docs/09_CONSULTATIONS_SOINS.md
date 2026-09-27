# Consultations, constantes et soins (étape 6a)

## 1. Consultation (rapport C4)

```
OUVERTE ──(première saisie clinique)──▶ EN_COURS ──▶ CLOTUREE ──▶ addendums seulement
   └──────────────────────────────▶ ANNULEE (motif obligatoire)
```

| Méthode | Route | Droit |
|---|---|---|
| GET | `/consultations?patient_id=&status=&type=&mine=1&from=&to=` | `consultations.read` |
| POST | `/consultations` | `consultations.create` (accueil, médecin) |
| GET | `/consultations/{id}` | `consultations.read` |
| PUT | `/consultations/{id}` — motif, symptômes, examen clinique, conclusion, praticien, service, `version` | `consultations.update` + droits médicaux |
| POST | `/consultations/{id}/close` | `consultations.close` |
| POST | `/consultations/{id}/cancel` — `{reason}` | `consultations.update` |
| POST | `/consultations/{id}/vitals` | `vitals.record` |
| POST / DELETE | `/consultations/{id}/diagnoses[/{diagnosisId}]` — libellé, code CIM-10 facultatif, principal/secondaire, certitude | `diagnoses.write` |
| POST | `/consultations/{id}/notes` — `{content}` | `consultations.update` |
| GET | `/patients/{id}/consultations` | `consultations.read` |
| GET / POST | `/patients/{id}/vitals` | droits médicaux / `vitals.record` |

**Types**
- `CLINIQUE` ;
- `SUIVI`, qui exige la consultation d'origine du même patient ;
- `URGENCE`, si le paramètre `consultations.urgent_enabled` est activé ;
- `DOMICILE`, ouvert automatiquement au démarrage d'une visite à domicile (`/homecare/{id}/start`), jamais directement.

**Rendez-vous** : une consultation ouverte avec `appointment_id` passe le rendez-vous à `HONORE` (voir [13_RENDEZ_VOUS.md](13_RENDEZ_VOUS.md)).

**Règles**
- Le patient doit être validé. Une consultation ne peut pas commencer dans le futur.
- Une fois clôturée, une consultation n'est plus modifiable : toute correction devient une note `ADDENDUM`, datée et signée.
- **Confidentialité (D-010)** :
  - le contenu clinique (motif, symptômes, examen, conclusion, constantes, diagnostics, notes, observations des soins) n'est renvoyé qu'aux utilisateurs qui ont les droits médicaux ;
  - l'accueil et l'administration voient les métadonnées (patient, type, statut, praticien, dates) ;
  - chaque consultation du contenu est auditée ;
  - le journal d'audit enregistre **le nom** des champs modifiés, jamais leur contenu clinique.
- **Aucune règle clinique codée** : le logiciel enregistre, le professionnel décide.

## 2. Constantes

- Température, tension, pouls, fréquence respiratoire, SpO2, poids, taille, glycémie : au moins une mesure par saisie.
- **Ajout seulement** : une erreur se corrige par une nouvelle mesure. Il n'y a donc jamais de conflit de synchronisation.
- Les bornes de saisie servent seulement à écarter les fautes de frappe physiquement impossibles (37,5 tapé 375). Ce ne sont pas des normes cliniques.
- L'IMC (poids / taille²) est calculé pour aider la lecture, sans interprétation.
- La saisie est possible dans une consultation ouverte ou hors consultation (soins infirmiers).

## 3. Soins (rapport C6)

```
PLANIFIE ──perform──▶ REALISE (définitif : il a eu lieu, il sera facturé)
    └──cancel (motif)──▶ ANNULE
```

| Méthode | Route | Droit |
|---|---|---|
| GET | `/treatments?patient_id=&status=&date=&mine=1` (liste « soins à faire ») | `treatments.read` |
| POST | `/treatments` — patient, type de soin actif, consultation ouverte facultative, `PLANIFIE` (+ `scheduled_for`) ou `REALISE` | `treatments.perform` |
| GET / PUT | `/treatments/{id}` (date prévue, observations) | `treatments.read` / `treatments.perform` |
| POST | `/treatments/{id}/perform`, `/treatments/{id}/cancel` | `treatments.perform` |

Les observations sont des données médicales : elles sont absentes des réponses sans droits médicaux.

## 4. Synchronisation

| `entity` | Opérations |
|---|---|
| `consultation` | CREATE, UPDATE, ACTION `close`, ACTION `cancel` (`payload.reason`) |
| `vital_sign` | CREATE (`payload.consultation_id` ou `payload.patient_id`) |
| `consultation_diagnosis` | CREATE, DELETE (consultation ouverte) |
| `consultation_note` | CREATE (note ou addendum) |
| `treatment` | CREATE, UPDATE, ACTION `perform`, ACTION `cancel` |

**Action hors ligne** : l'opération `ACTION` porte un champ `action`. Elle passe par la même machine à états qu'en ligne. Une transition devenue impossible (par exemple ajouter une mesure à une consultation clôturée entre-temps) est renvoyée `REJECTED` avec son code (`CONSULTATION_CLOSED`, `INVALID_TRANSITION`).

**Périmètre** : une consultation dont l'utilisateur est le praticien, ou un soin qu'il a réalisé, fait entrer le patient dans son périmètre pour la durée `sync.offline_scope_months`.

**Instantané complet** : quand un dossier entre dans le périmètre d'un utilisateur (épinglage, nouveau médecin traitant), l'**ensemble** du dossier est réinscrit dans le journal : identité, données, consultations, constantes, diagnostics, notes et soins. Chaque module y contribue en implémentant `PatientScopedEntity`.

## 5. Tests

- `tests/Api/ConsultationsTest.php` : parcours complet de l'accueil à l'addendum, métadonnées seules pour l'accueil, droits par rôle, validations, annulation, constantes hors consultation, conflit de version, audit sans contenu clinique, filtres.
- `tests/Api/TreatmentsTest.php` : soin programmé puis réalisé une seule fois, validations, observations protégées, annulation motivée.
- `tests/Api/SyncTest.php` : consultation complète hors ligne clôturée par action, rejet après clôture, instantané à l'épinglage, action inconnue.
