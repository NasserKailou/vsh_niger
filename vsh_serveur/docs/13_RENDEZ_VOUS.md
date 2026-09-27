# Rendez-vous (étape 8b)

## 1. Cycle de vie (rapport C3)

```
Patient : DEMANDE ──confirm (accueil, + praticien)──▶ CONFIRME
Accueil : création directe ──▶ CONFIRME
CONFIRME / DEMANDE ──reschedule──▶ DEPLACE (reste actif, `rescheduled_from` garde l'heure initiale)
Jour J : check-in (arrivée) ; consultation ouverte avec `appointment_id` ──▶ HONORE ; sinon no-show ──▶ ABSENT
Annulation ──▶ ANNULE : patient jusqu'à N heures avant ; accueil avec motif
```

| Méthode | Route | Qui |
|---|---|---|
| GET | `/appointments/slots?service_id=&date=AAAA-MM-JJ` : créneaux et places libres | patient (`appointments.request_self`) ou personnel (`appointments.read`) |
| GET | `/appointments?date=&from=&to=&service_id=&status=&patient_id=&mine=1` | `appointments.read` : tout pour l'accueil (`appointments.manage`), son planning pour un praticien |
| POST | `/appointments` : `patient_id`, `service_id`, `scheduled_start`, `reason`, `practitioner_id` (personnel seulement) | patient pour ses dossiers, ou `appointments.manage` |
| GET | `/appointments/{id}` | patient concerné, accueil, praticien affecté |
| POST | `/appointments/{id}/confirm`, avec `practitioner_id` | `appointments.manage` |
| POST | `/appointments/{id}/reschedule`, avec `scheduled_start` et `practitioner_id` | `appointments.manage` |
| POST | `/appointments/{id}/cancel`, avec `reason` (obligatoire pour la clinique) | patient concerné ou `appointments.manage` |
| POST | `/appointments/{id}/check-in` : le jour même | `appointments.manage` |
| POST | `/appointments/{id}/no-show` : après l'heure, sans arrivée enregistrée | `appointments.manage` |
| GET | `/me/patients/{id}/appointments` | `self.record.read` |

## 2. Créneaux

- **Calcul par le serveur** : les créneaux découlent des **plages horaires configurées** du service (`/services/{id}/schedules` : jour, début, fin, durée du créneau, capacité), en **heure locale de la clinique** (`app.timezone`). Les rendez-vous actifs (demandé, confirmé, déplacé, honoré) en sont retirés.
- **Contrôles à la réservation** :
  - l'heure doit tomber exactement sur un créneau (`422`) et être à venir ;
  - elle doit rester dans l'horizon `appointments.max_days_ahead` ;
  - le créneau doit avoir de la place (`409 SLOT_FULL`) ;
  - le patient ne doit pas avoir déjà un rendez-vous à la même heure (`409 PATIENT_BUSY`).
- **Concurrence** : les réservations d'un même service sont sérialisées par un verrou. Deux personnes ne peuvent pas prendre la dernière place en même temps.
- **Côté patient** : il ne choisit pas le praticien, qui est affecté par la clinique à la confirmation. Un dossier en attente de validation peut demander un rendez-vous ; l'accueil le validera à la visite.

## 3. Jour J

- **Consultation liée** : une consultation ouverte avec `appointment_id` (rendez-vous confirmé ou déplacé, même patient) passe le rendez-vous à **HONORE**. Elle reprend le service et enregistre l'arrivée si elle ne l'était pas. Un même rendez-vous ne peut pas donner deux consultations.
- **Absence** : un rendez-vous confirmé dont l'heure est passée, sans arrivée enregistrée, peut être déclaré **ABSENT**.

## 4. Notifications et confidentialité

- **Notifications** : le patient est prévenu de la confirmation, du déplacement et de l'annulation par la clinique. Le message ne contient **que la date et l'heure** : ni service (la spécialité peut être sensible), ni motif.
- **Accès** : l'infirmier ou le médecin ne voit que les rendez-vous dont il est le praticien. L'accueil voit tout le planning.

## 5. Synchronisation

| `entity` | Opérations |
|---|---|
| `appointment` | CREATE (demande mise en file par le patient), ACTION `confirm`, `reschedule`, `cancel`, `check_in`, `no_show` |

- **Portée** : le rendez-vous est transmis au patient concerné et au **praticien affecté**, qui a ainsi son planning hors ligne. Quand le praticien change, l'ancien reçoit le statut seul (`available: false`) pour retirer le rendez-vous de son planning.
- **Revérification** : une demande hors ligne est revérifiée à la réception. Si le créneau est devenu complet entre-temps, elle est refusée (`REJECTED`, `SLOT_FULL`).

## 6. Rappels

Les rappels avant rendez-vous (SMS ou notification push, la veille) seront ajoutés avec l'étape 13 (notifications push et tâches planifiées).

## 7. Tests

`tests/Api/AppointmentsTest.php` couvre :
- le calcul des créneaux, la demande du patient, l'heure hors grille, le doublon et le praticien imposé ;
- la confirmation et la notification sans service ;
- le planning par praticien et la capacité par créneau ;
- l'annulation hors délai, le déplacement, les contrôles du jour J et l'annulation par la clinique ;
- l'arrivée, la consultation liée (HONORE), l'absence et le suivi par le patient ;
- la demande hors ligne et le planning synchronisé du praticien.
