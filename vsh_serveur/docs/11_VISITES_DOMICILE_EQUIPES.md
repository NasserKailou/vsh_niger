# Équipes et visites à domicile (étape 7)

## 1. Équipes

| Méthode | Route | Droit |
|---|---|---|
| GET | `/teams?active=&mobile=` | `teams.read` |
| POST / PUT | `/teams[/{id}]` : `code`, `label`, `description`, `is_mobile`, `active` | `teams.manage` |
| GET | `/teams/{id}`, avec les membres actuels et passés | `teams.read` |
| POST | `/teams/{id}/members` : `user_id`, `team_role`, `from_date` (par défaut aujourd'hui), `to_date` | `teams.manage` |
| PUT | `/teams/{id}/members/{memberId}` : `team_role`, `to_date` (fin d'appartenance) | `teams.manage` |
| GET | `/me/teams` : mes équipes actuelles | connecté |

- **Appartenance datée** : une personne ne peut pas être inscrite deux fois dans la même équipe sur des périodes qui se chevauchent (`409 ALREADY_MEMBER`).
- **Historique** : on met fin à une appartenance, on ne la supprime jamais. On sait donc qui faisait partie de l'équipe à une date donnée.
- **Membres** : seul le personnel actif peut devenir membre, jamais un compte patient.

## 2. Visite à domicile (rapport C5)

```
NOUVELLE ──approve (ou validation automatique)──▶ EN_ATTENTE
EN_ATTENTE ──accept (équipe) / assign (régulation)──▶ PRISE_EN_CHARGE
PRISE_EN_CHARGE ──depart──▶ EN_ROUTE ──arrive──▶ SUR_PLACE ──start──▶ EN_COURS ──complete──▶ TERMINEE
PRISE_EN_CHARGE ──release (désistement, motif)──▶ EN_ATTENTE
Sorties : ANNULEE_PATIENT (jusqu'à EN_ROUTE) · ANNULEE_CLINIQUE (motif, jusqu'à SUR_PLACE) · ECHEC (motif)
TERMINEE ──▶ FACTUREE : posé par la facturation (étape 8)
```

| Méthode | Route | Qui |
|---|---|---|
| GET | `/homecare?status=A,B&open=1&queue=1&mine=1&patient_id=&team_id=` | `homecare.read` (voir « Visibilité ») |
| POST | `/homecare` | personnel (`homecare.request`) ou patient pour ses dossiers (`homecare.request_self`) |
| GET | `/homecare/{id}` : fiche et historique | selon la visibilité |
| POST | `/homecare/{id}/approve` | `homecare.dispatch` |
| POST | `/homecare/{id}/accept`, avec `team_id` si l'utilisateur est dans plusieurs équipes | `homecare.intervene`, membre d'une équipe mobile |
| POST | `/homecare/{id}/assign`, avec `team_id` et `comment` (affectation ou réaffectation) | `homecare.dispatch` |
| POST | `/homecare/{id}/release`, avec `reason` | équipe affectée ou régulation |
| POST | `/homecare/{id}/depart`, `arrive`, `start`, `complete`, `fail` (`reason`) | membres **actuels** de l'équipe affectée |
| POST | `/homecare/{id}/cancel`, avec `reason` (obligatoire pour la clinique) | patient, régulation, ou demandeur avant prise en charge |
| POST | `/homecare/{id}/track`, avec `points: [{latitude, longitude, accuracy_m, captured_at}]` (100 au plus) | équipe affectée, visite en cours |
| GET | `/me/patients/{id}/homecare` : suivi par le patient | `self.record.read` |
| GET | `/map/homecare` : carte des visites en cours | `map.read` ou `homecare.dispatch` |

### Demande

La demande porte :
- le motif et l'urgence (`NORMALE` / `URGENTE`) ;
- une heure souhaitée, facultative ;
- la **position GPS** (latitude, longitude, précision, heure de capture) ;
- l'adresse ou un repère : au moins l'une des deux informations, position ou repère, est obligatoire ;
- le téléphone à joindre, qui est par défaut celui du dossier.

Règles de création :
- **une seule visite ouverte par patient** (`409 HOMECARE_ALREADY_OPEN`), ce qui évite les doubles demandes ;
- `homecare.auto_accept_new_requests` : à `true`, la demande passe directement en file d'attente ; sinon, la régulation la valide (`approve`).

### Affectation (D-003)

Le paramètre `homecare.dispatch_mode` fixe le mode d'affectation :
- `BOTH` (par défaut) : auto-attribution et régulation ;
- `SELF_ASSIGN` : auto-attribution seulement (`403 DISPATCH_DISABLED` pour la régulation) ;
- `DISPATCH_ONLY` : régulation seulement (`403 SELF_ASSIGN_DISABLED`). Dans ce mode, la file d'attente n'est pas montrée aux équipes.

L'acceptation se fait par mise à jour **conditionnelle** : la première équipe gagne et les suivantes reçoivent `409 INVALID_TRANSITION`.

Une réaffectation clôt la prise en charge précédente (`homecare_interventions.released_at` et motif) et en ouvre une nouvelle. L'historique complet est donc conservé.

### Terrain

- **Horodatage** : chaque action peut porter `at`, l'heure réelle sur l'appareil, utile hors ligne. Elle est enregistrée à côté de l'heure de réception par le serveur, et une heure dans le futur est refusée. L'historique est présenté dans l'ordre d'application.
- **Positions** : les positions de départ et d'arrivée sont enregistrées dans `homecare_locations` (`DEPART`, `ARRIVEE`) et dans l'historique. Les points de trajet (`TRACE`) alimentent la carte de régulation.
- **Démarrage** : `start` ouvre automatiquement la **consultation `DOMICILE`**, liée à la visite. L'application peut fournir `consultation_id`, généré hors ligne, pour y rattacher tout de suite constantes, soins et prescriptions.
- **Clôture de la consultation** : elle reste une décision médicale (`consultations.close`), distincte de la fin de visite.
- **Historique** : chaque transition est inscrite dans `homecare_status_history`, qui est immuable. Elle garde l'état de départ, l'état d'arrivée, l'auteur, l'heure de l'appareil, l'heure de réception, la position, le commentaire et l'appareil.

### Visibilité

| Utilisateur | Voit |
|---|---|
| Régulation (`homecare.dispatch`), demandeur | tout, avec l'historique |
| Membre actuel de l'équipe affectée | tout, avec l'historique |
| Autre intervenant | les demandes `EN_ATTENTE` (si l'auto-attribution est permise). Ensuite, **le statut seul** (`available: false`), pour retirer la demande de sa file |
| Patient | ses demandes : statut, équipe, heures de prise en charge, de départ et d'arrivée. Ni historique interne, ni téléphone de contact |

La **carte** ne montre ni le motif ni le nom du patient. Elle affiche le numéro de dossier, le statut, l'urgence, la position, l'équipe et sa dernière position connue.

**Notifications**, sans aucun détail médical :
- au patient : prise en charge, équipe en route, annulation par la clinique ;
- aux membres de l'équipe : visite affectée ou annulée.

### Examens validés par l'équipe (D-005)

Un examen prescrit dans la consultation `DOMICILE` peut être validé par un membre actuel de l'équipe affectée à la visite, s'il a le droit `examinations.validate`.

## 3. Géolocalisation

### Contrôles des positions reçues

- Latitude et longitude vont toujours ensemble. Les valeurs hors des bornes sont refusées.
- La position **(0, 0)** est refusée partout : création de la demande, départ, arrivée, trajet, relevé du domicile et adresses du patient. C'est la valeur que renvoient certains GPS avant d'avoir une position ; elle ne correspond jamais à un domicile (golfe de Guinée).
- **Trajet idempotent** : un lot de points renvoyé après une coupure réseau n'est pas enregistré deux fois. La réponse indique `recorded` et `duplicates`.
- Les distances sont calculées **à vol d'oiseau**, par la formule de haversine (`Vsh\Core\Support\Geo`). Ce ne sont pas des distances routières.

### Paramètres (modifiables par l'administrateur)

| Clé | Défaut | Rôle |
|---|---|---|
| `geo.arrival_radius_m` | 300 | Au-delà de cette distance, une arrivée est signalée « loin du domicile ». L'arrivée n'est pas bloquée, et la marge d'imprécision du GPS est déduite avant la comparaison. |
| `geo.low_accuracy_m` | 100 | Au-delà de cette précision, une position est signalée imprécise. Les points imprécis sont exclus de la distance parcourue et du tracé. |
| `geo.position_stale_minutes` | 10 | Âge au-delà duquel la dernière position d'une équipe est signalée ancienne (réseau, batterie ou partage arrêté). |
| `geo.track_interval_seconds` | 30 | Intervalle d'envoi de la position pendant une visite. |
| `geo.trace_retention_days` | 90 | Durée de conservation des points de trajet (voir la purge). |

Ces seuils servent uniquement à l'information et au classement. Aucun soin n'est bloqué par le GPS.

### API

| Méthode et chemin | Accès | Rôle |
|---|---|---|
| `GET /homecare/{id}` | Visibilité complète | Bloc `geo` (voir ci-dessous) |
| `GET /homecare/{id}/track` | Régulation, équipe affectée | Trajet par prise en charge successive (`segments`, avec `points`, `distance_m` et `active`), domicile et seuils |
| `POST /homecare/{id}/home-location` | Régulation (visite ouverte). Membre de l'équipe **sur place** (`SUR_PLACE` ou `EN_COURS`) | Position exacte du domicile. Avec `update_patient_address: true`, elle est aussi enregistrée sur l'adresse principale du dossier (créée si besoin), avec la trace d'audit `PATIENT_ADDRESS_GPS_UPDATED` |
| `GET /homecare/{id}/dispatch-options` | Régulation | Équipes mobiles actives, classées par distance entre leur dernière position récente et le domicile, puis par charge. Donne pour chacune le nombre de membres, les visites en cours et la position |
| `GET /map/homecare` | Carte | En plus : précision du domicile (`accuracy_m`, `imprecise`) et, pour l'équipe, `stale`, `imprecise` et `distance_m` |

Contenu du bloc `geo` de la fiche :

- `departure` et `arrival` : position, précision et `distance_m` au domicile. L'arrivée porte aussi `far`.
- `team_position` : dernière position connue et `stale`, fournie seulement pendant `EN_ROUTE`, `SUR_PLACE` ou `EN_COURS`.
- `home_imprecise` et `settings`.

Le patient ne reçoit jamais ce bloc.

### Vie privée du personnel

- La position d'un soignant n'est collectée **que pendant une visite en cours** (départ, arrivée, trajet), jamais en dehors.
- La position n'est affichée qu'à la régulation et à l'équipe affectée.
- **Purge** : `php bin/console.php geo:purge` supprime les points de trajet plus anciens que `geo.trace_retention_days`, pour les visites closes ou les prises en charge abandonnées. Les positions de départ et d'arrivée, preuves de passage, sont conservées. À planifier chaque jour (tâche planifiée Windows ou cron).

## 4. Synchronisation

| `entity` | Opérations |
|---|---|
| `homecare_request` | CREATE, ACTION `approve`, `accept`, `assign`, `release`, `depart`, `arrive`, `start` (`consultation_id`), `complete`, `fail`, `cancel`, `track` |

**Journal par équipe** : le pull inclut désormais les changements destinés aux **équipes actuelles** de l'utilisateur (`sync_changes.team_id`).

**Changement de la file d'attente** : quand une demande entre dans la file ou en sort, un changement global est émis. Chaque appareil l'évalue avec ses propres droits : fiche complète, statut seul, ou rien.

**Périmètre** : les membres d'une équipe reçoivent le **dossier complet** des patients de leurs visites en cours. À l'acceptation, le dossier est réinscrit dans le journal (instantané), pour être disponible hors ligne sur le terrain.

**Terrain hors ligne** : la tournée complète peut être faite sans réseau :

```
accept → depart → arrive → start (consultation_id généré) → vital_sign (depends_on: start) → complete
```

Une action devenue impossible est refusée avec son code, par exemple si une autre équipe a pris la visite ou si la clinique l'a annulée entre-temps (`REJECTED`, `INVALID_TRANSITION`).

## 5. Tests

- `tests/Api/TeamsTest.php` : appartenance datée, chevauchement refusé, fin d'appartenance, droits, personnel uniquement.
- `tests/Api/HomecareTest.php` couvre :
  - le parcours complet en auto-attribution, avec la course entre deux équipes, la consultation `DOMICILE`, l'historique avec les positions et le suivi par le patient ;
  - la régulation (affectation, réaffectation, désistement) ;
  - les règles d'annulation ;
  - l'échec motivé, la carte sans données médicales et les points de trajet ;
  - le mode `DISPATCH_ONLY` ;
  - la tournée entièrement hors ligne ;
  - la validation D-005 par l'équipe en charge.
- `tests/Api/HomecareGeoTest.php` couvre :
  - la distance calculée sur la sphère ;
  - le refus de la position (0, 0) partout ;
  - le trajet idempotent, sa distance qui exclut les points imprécis, et son accès limité à la régulation et à l'équipe ;
  - l'arrivée loin du domicile, signalée sans être bloquée ;
  - la position d'équipe ancienne sur la carte ;
  - le relevé du domicile sur place, avec la mise à jour sans doublon de l'adresse du dossier et l'audit ;
  - le classement des équipes par proximité ;
  - la purge, limitée aux trajets des visites closes.
