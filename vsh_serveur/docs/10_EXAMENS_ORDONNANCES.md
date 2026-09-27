# Examens et ordonnances (étape 6b)

## 1. Examens (rapport C7)

```
PRESCRIT ──start──▶ EN_COURS ──complete──▶ TERMINE ──validate──▶ VALIDE (visible par le patient)
   └──────────────────┴──cancel (motif)──▶ ANNULE
```

| Méthode | Route | Droit |
|---|---|---|
| GET | `/examinations?patient_id=&status=&queue=1&mine=1` | `examinations.read` |
| POST | `/examinations`, avec `patient_id`, `examination_type_id`, `consultation_id` facultatif, `priority` (`NORMALE` / `URGENTE`), `clinical_info` | `examinations.prescribe` |
| GET | `/examinations/{id}` | `examinations.read` |
| POST | `/examinations/{id}/start` | `examinations.perform` |
| POST | `/examinations/{id}/results`, avec `{results: [...], comment}` | `examinations.perform` |
| POST | `/examinations/{id}/complete` | `examinations.perform` |
| POST | `/examinations/{id}/validate` | `examinations.validate` et règle D-005 |
| POST | `/examinations/{id}/cancel`, avec `{reason}` | prescription ou réalisation |
| GET | `/me/patients/{id}/examinations`, qui renvoie les examens **validés** du dossier | `self.record.read` |

La file du technicien s'obtient avec `?queue=1` : elle contient les examens `PRESCRIT` et `EN_COURS`, les urgents en premier.

### Résultats structurés

Chaque ligne de résultat prend l'une de ces formes :
- `{parameter_id, value_numeric | value_text}`, pour un paramètre configuré du type d'examen ;
- `{label, value_numeric | value_text, unit}`, pour un résultat libre.

Règles de saisie :
- le paramètre doit appartenir au type d'examen ;
- la valeur doit respecter le type du paramètre :
  - `NUMERIC` : une valeur numérique ;
  - `TEXT` : un texte ;
  - `CHOICE` : l'une des valeurs `choices` configurées.

Valeurs de référence :
- elles sont **copiées** dans le résultat au moment de la saisie (`reference_text`). Une modification ultérieure du référentiel ne change donc pas un résultat déjà rendu ;
- `is_abnormal` indique seulement que la valeur sort des bornes `ref_min` / `ref_max` saisies par la clinique. Le logiciel n'applique **aucune norme codée** et ne donne aucune interprétation.

Une nouvelle saisie remplace la précédente, avec historique en suppression logique, tant que l'examen n'est pas terminé. Une saisie sur un examen `PRESCRIT` le démarre automatiquement.

### Validation (D-005)

Peuvent valider les utilisateurs qui ont `examinations.validate` et qui sont dans l'un de ces cas :
- **médecin traitant** du patient ;
- **prescripteur** de l'examen ;
- **membre de l'équipe** affectée à la visite à domicile d'où vient la consultation. L'appartenance est datée (`team_members`) ; voir [11_VISITES_DOMICILE_EQUIPES.md](11_VISITES_DOMICILE_EQUIPES.md).

Le technicien qui a saisi les résultats ne peut jamais les valider (`403 SELF_VALIDATION`). La base le garantit aussi par la contrainte `ck_examinations_segregation`.

À la validation, le compte patient reçoit une notification « Résultat disponible », **sans aucun détail médical**.

### Confidentialité

- **Personnel avec droits médicaux** : il voit tout le contenu. La consultation d'une fiche est auditée (`MEDICAL_RECORD_VIEWED`).
- **Technicien** : il voit ce qu'il faut pour réaliser l'examen (identité minimale, renseignements cliniques, résultats), sans accès au reste du dossier.
- **Administration** : elle ne voit que les métadonnées (type, statut, dates, intervenants).
- **Patient** : il ne voit que les examens validés, avec leurs résultats.

## 2. Modèles d'ordonnance (rapport C8)

```
BROUILLON ──approve──▶ ACTIF ──archive──▶ ARCHIVE
    ▲                    │
    └── modification ────┘  (version clinique + 1, nouvelle approbation obligatoire)
```

| Méthode | Route | Droit |
|---|---|---|
| GET | `/prescription-templates?status=&q=&population=` | `prescription_templates.read`. Seuls les modèles actifs sont visibles, sauf pour ceux qui gèrent ou approuvent |
| GET | `/prescription-templates/suggest?patient_id=&pathology=` | `prescription_templates.read` et droits médicaux |
| POST / PUT | `/prescription-templates[/{id}]` : nom, pathologie, population, âges en mois, poids en kg, contre-indications, notes d'usage, `items` | `prescription_templates.manage` |
| POST | `/prescription-templates/{id}/approve` | `prescription_templates.approve` (médecin) |
| POST | `/prescription-templates/{id}/archive` | gestion ou approbation |

**Les modèles sont des données de la clinique.** Aucun protocole n'est fourni ni codé dans le logiciel.

La **suggestion** applique uniquement les critères saisis sur le modèle : l'âge (calculé depuis la date de naissance) et le dernier poids mesuré. Quand un critère ne peut pas être vérifié (âge ou poids inconnu), le modèle n'est pas exclu : il est proposé avec `criteria_to_check`.

Les modèles actifs sont inclus dans `/reference/bundle` (`prescription_templates`) pour le hors ligne. En différentiel, le bundle contient aussi les modèles sortis de l'état actif ; l'application ne garde que ceux dont le statut est `ACTIF`.

## 3. Ordonnances

```
BROUILLON ──sign (prescripteur)──▶ SIGNEE (visible par le patient, non modifiable)
BROUILLON / SIGNEE ──cancel (motif)──▶ ANNULEE
```

| Méthode | Route | Droit |
|---|---|---|
| GET | `/prescriptions?patient_id=&status=&mine=1` | `prescriptions.read` |
| POST | `/prescriptions`, avec `patient_id`, `consultation_id` facultatif, `template_id` facultatif, `notes`, `items` | `prescriptions.write` et droits médicaux |
| GET | `/prescriptions/{id}` | `prescriptions.read` |
| PUT | `/prescriptions/{id}`, pour modifier `notes` et `items` (remplacées en bloc) d'un brouillon, avec `version` | `prescriptions.write`, prescripteur seulement |
| POST | `/prescriptions/{id}/sign` | `prescriptions.sign`, prescripteur seulement |
| POST | `/prescriptions/{id}/cancel`, avec `{reason}` | `prescriptions.write`, prescripteur seulement |
| GET | `/me/patients/{id}/prescriptions`, qui renvoie les ordonnances **signées** | `self.record.read` |

### Lignes

Une ligne d'ordonnance peut viser un médicament du référentiel (`medication_id`) ou être saisie librement (`medication_label`).

- **Médicament du référentiel** : le libellé, le dosage, la forme et la voie sont proposés à partir du référentiel et restent modifiables.
- **Figement** : le libellé est figé au moment de la prescription.

Les autres champs de la ligne sont `quantity`, `posology`, `frequency`, `duration` et `instructions`.

### Rédaction depuis un modèle

Avec `template_id` et sans `items`, les lignes du modèle **actif** sont copiées. L'ordonnance garde la référence du modèle et sa version clinique, puis le prescripteur peut ajuster les lignes avant de signer.

### Alertes d'allergie

Tant que l'ordonnance est en brouillon, la réponse contient `alerts`. Une alerte est levée quand une allergie du patient vise le médicament du référentiel, ou quand l'allergène est cité dans le libellé. Ces alertes sont **informatives et jamais bloquantes** : le prescripteur décide.

À la signature, le compte patient reçoit une notification « Nouvelle ordonnance », sans aucun détail.

L'édition PDF de l'ordonnance sera ajoutée avec l'interface web (étape 9).

### Impression

Seule une ordonnance **signée** s'imprime :

- `GET /prescriptions/{id}/pdf` exige les droits médicaux sur le dossier ;
- le patient passe par `GET /me/patients/{id}/prescriptions/{prescriptionId}/pdf` ;
- chaque export est audité (`PRESCRIPTION_EXPORTED`).

Voir [15_DOCUMENTS_PDF.md](15_DOCUMENTS_PDF.md).

## 4. Synchronisation

| `entity` | Opérations |
|---|---|
| `examination` | CREATE (prescription), ACTION `start`, `record_results` (`payload.results`), `complete`, `validate`, `cancel` (`payload.reason`) |
| `prescription` | CREATE, UPDATE (`notes`, `items`, brouillon seulement), ACTION `sign`, `cancel` |

**Périmètre**
- Le technicien (`examinations.perform`) reçoit les dossiers qui ont un examen à réaliser, puis ceux dont il a réalisé un examen pendant la durée `sync.offline_scope_months`.
- Les examens et ordonnances saisis par un utilisateur font entrer le patient dans son périmètre.

**Compte patient** : il ne reçoit que les examens validés et les ordonnances signées.

**Opération devenue impossible** : elle est refusée avec son code. C'est le cas, par exemple, d'une modification envoyée après la signature, ou d'une validation par le technicien lui-même (`REJECTED`, `INVALID_TRANSITION` / `FORBIDDEN`).

## 5. Tests

- `tests/Api/ExaminationsTest.php` couvre :
  - le parcours complet de la prescription au résultat vu par le patient ;
  - la validation des valeurs (`CHOICE`, paramètre étranger), le calcul hors bornes et la valeur de référence copiée ;
  - la règle D-005 (médecin traitant, tiers refusé) et l'interdiction de valider ses propres résultats ;
  - la confidentialité vis-à-vis de l'administration et de l'accueil ;
  - l'annulation motivée et la synchronisation hors ligne du technicien.
- `tests/Api/PrescriptionsTest.php` couvre :
  - le cycle de vie d'un modèle (brouillon, approbation, retour en brouillon après modification, archivage, bundle) ;
  - la suggestion par âge et par poids ;
  - les alertes d'allergie ;
  - la signature par le seul prescripteur, le conflit de version et l'accès du patient aux seules ordonnances signées ;
  - les droits par rôle ;
  - la rédaction et la signature hors ligne, avec refus d'une modification après signature.
