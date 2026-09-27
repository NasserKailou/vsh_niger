# Facturation (étape 8, D-004)

**Les paiements se font hors de la plateforme.** La clinique émet la facture et le patient la consulte dans l'application. L'état de règlement est **déclaré** par une personne habilitée, à titre d'information : aucune transaction n'est enregistrée ni traitée.

## 1. Cycle de vie

```
BROUILLON ──issue──▶ EMISE (numéro définitif FAC-AAAA-000001, visible par le patient) ──cancel (motif)──▶ ANNULEE
BROUILLON ──cancel (motif)──▶ ANNULEE

Sur une facture EMISE, état de règlement déclaratif : NON_REGLEE ⇄ PARTIELLEMENT_REGLEE ⇄ REGLEE
```

| Méthode | Route | Droit |
|---|---|---|
| GET | `/invoices?patient_id=&status=&settlement_status=&number=&from=&to=` | `invoices.read` |
| GET | `/invoices/summary?from=&to=` : suivi des impayés | `invoices.read` |
| POST | `/invoices` : `patient_id`, `consultation_id` et/ou `homecare_request_id`, `items`, `notes` | `invoices.manage` |
| GET | `/invoices/{id}` : lignes et historique | `invoices.read` |
| PUT | `/invoices/{id}` : `notes`, `discount_amount`, `version` (brouillon) | `invoices.manage` |
| POST / DELETE | `/invoices/{id}/items[/{itemId}]` (brouillon) | `invoices.manage` |
| POST | `/invoices/{id}/issue` | `invoices.issue` |
| POST | `/invoices/{id}/cancel`, avec `reason` | `invoices.issue` |
| POST | `/invoices/{id}/settlement` : `settlement_status`, `declared_paid_amount`, `comment` | `invoices.settlement_declare` |
| GET | `/me/patients/{id}/invoices` : factures émises du dossier | `self.invoices.read` |

Dans les rôles par défaut, l'administration et l'accueil facturent. Les soignants ne voient pas les factures.

## 2. Montants

- **Aucun prix n'est codé.** Chaque ligne **copie** le tarif en vigueur à la date de l'acte, tiré du référentiel daté `/tariffs` (date locale de la clinique, `app.timezone`). Une évolution de tarif ne modifie donc jamais une facture existante.
- **Génération automatique** : avec `consultation_id` ou `homecare_request_id`, le brouillon reprend les **soins réalisés** et les **examens effectués** (terminés ou validés) de la consultation ou de la visite.
- **Tarif manquant** : un élément sans tarif applicable n'est pas ajouté. Il est signalé dans `missing_tariffs`, pour que l'administration complète le référentiel.
- **Pas de double facturation** : un soin ou un examen déjà présent sur une facture non annulée n'est pas repris. Après annulation, il redevient facturable.
- **Lignes saisies** :
  - `MEDICAL_ACT` ou `MEDICATION` avec `reference_id` : le prix vient du tarif du jour et ne se saisit pas (`422`) ;
  - `OTHER` : libellé et prix saisis par la personne habilitée (par exemple un certificat).
- **Montants entiers** en devise `app.currency` (XOF). La base contrôle que le net est égal au total moins la remise, et que la remise ne dépasse pas le total.

## 3. Émission, annulation, règlement

- **Émission** : elle exige au moins une ligne et attribue un numéro séquentiel par année (`invoices.number_prefix`). Le brouillon devient non modifiable.
- **Notification au patient** : « Nouvelle facture », **sans montant ni détail**.
- **Visite à domicile liée** : si la visite est `TERMINEE`, elle passe à **`FACTUREE`**. L'annulation de la facture la remet à `TERMINEE`, ce qui permet de la refacturer. Les deux changements sont historisés dans la visite.
- **Règlement déclaré** : les montants doivent être cohérents avec l'état déclaré :
  - non réglée : 0 ;
  - partiellement réglée : strictement entre 0 et le net ;
  - réglée : le net, qui est la valeur par défaut.
- **Annulation après déclaration** : une facture sur laquelle un règlement est déclaré ne peut pas être annulée (`409 SETTLEMENT_DECLARED`). Il faut d'abord remettre l'état à « non réglée ».
- **Traçabilité** : chaque changement de statut et de règlement est inscrit dans `invoice_status_history` (avec auteur, date, montant et commentaire) et dans l'audit.

## 4. Patient et hors ligne

- **Côté patient** : il voit ses factures émises, et celles annulées après émission, avec leurs lignes. Il ne voit ni les brouillons ni l'historique interne.
- **Synchronisation** : entité `invoice` en **lecture seule** (pull). Elle permet de consulter les factures hors ligne. La facturation elle-même se fait en ligne, car la numérotation et les tarifs relèvent du serveur.
- **PDF** : l'édition PDF de la facture sera ajoutée avec l'interface web (étape 9).

## 5. Document PDF

`GET /invoices/{id}/pdf` pour le personnel, `GET /me/patients/{id}/invoices/{invoiceId}/pdf` pour le patient (factures émises seulement). Chaque export est audité (`INVOICE_EXPORTED`). Voir [15_DOCUMENTS_PDF.md](15_DOCUMENTS_PDF.md).

## 6. Tests

`tests/Api/BillingTest.php` couvre :
- le brouillon généré depuis une consultation, avec un tarif manquant signalé et sans double facturation ;
- la remise, les lignes libres et le refus d'un prix saisi sur une ligne tarifée ;
- l'émission et sa numérotation, la notification sans montant ;
- les règlements déclarés et leur cohérence, puis l'annulation et son historique ;
- la visite à domicile qui passe à `FACTUREE`, puis revient à `TERMINEE` à l'annulation ;
- les droits, le résumé des impayés et l'accès du patient.
