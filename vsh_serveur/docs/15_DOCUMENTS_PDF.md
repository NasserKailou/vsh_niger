# Documents PDF (étape 9d)

Ce document décrit comment l'API produit les factures et les ordonnances au format PDF, qui peut les obtenir, et comment l'administrateur règle l'en-tête des documents.

## 1. Moteur

Le moteur est `Vsh\Core\Pdf\PdfDocument`. Il ne dépend d'aucune bibliothèque externe, car la production n'installe pas Composer.

- **Pages** : format A4.
- **Polices** : les trois polices standard Helvetica (normale, grasse, oblique), en codage WinAnsi, ce qui couvre les accents français.
- **Mise en page** :
  - la largeur du texte est mesurée à partir des chasses Adobe, ce qui permet les retours à la ligne et l'alignement à droite ;
  - un mot trop long pour la ligne est coupé ;
  - le moteur dessine aussi des traits, des rectangles et des couleurs de la charte.
- **Compression** : les flux sont compressés (FlateDecode) quand zlib est disponible.
- **Taille** : environ 2 Ko par document.

`Vsh\Core\Pdf\Letterhead` fournit ce qui est commun aux deux documents :

- l'en-tête, avec les bandeaux orange et vert de la charte ;
- l'en-tête réduit des pages suivantes ;
- le pied de page : texte libre, référence du document et « Page x / n ».

## 2. En-tête des documents (paramètre)

Aucune adresse ni aucun numéro de téléphone n'est écrit dans le code. Le nom de l'établissement vient de `app.clinic_name`. Le reste vient du paramètre JSON `documents.letterhead` :

```json
{ "address": "", "phone": "", "email": "", "invoice_footer": "", "prescription_footer": "" }
```

Un champ vide est simplement omis. La modification se fait par `PUT /settings`, droit `settings.manage`. Elle passera par l'écran « Paramètres » au bloc 9e.

## 3. Routes

| Méthode et chemin | Droit | Règles |
|---|---|---|
| `GET /invoices/{id}/pdf` | `invoices.read` | Tout état de facture. Un brouillon porte la mention « Document provisoire : facture non émise, sans valeur comptable ». Une facture annulée porte la mention « annulée », avec la date et le motif. |
| `GET /me/patients/{id}/invoices/{invoiceId}/pdf` | `self.invoices.read` | Uniquement pour un dossier rattaché au compte. Factures émises ou annulées après émission ; jamais un brouillon (réponse 404). |
| `GET /prescriptions/{id}/pdf` | `prescriptions.read` et droits médicaux sur le dossier (D-010) | Ordonnance **signée** seulement. Sinon, erreur 409 `NOT_SIGNED`. |
| `GET /me/patients/{id}/prescriptions/{prescriptionId}/pdf` | `self.record.read` | Ordonnance signée d'un dossier rattaché au compte. |

Toutes ces réponses sont renvoyées ainsi :

- `Content-Type: application/pdf` ;
- `Content-Disposition: attachment`, avec un nom de fichier sûr (`FAC-2026-000001.pdf`, `ordonnance-xxxxxxxx.pdf`) ;
- `Cache-Control: no-store, private`.

## 4. Contenu

**Facture** :

- numéro et date d'émission ;
- patient : nom, dossier, téléphone ;
- tableau des prestations : désignation, quantité, prix unitaire, montant ;
- total, remise, net à payer ;
- pour une facture émise : état du règlement déclaré et reste dû ;
- la mention « Le paiement est effectué en dehors de la plateforme. L'état de règlement indiqué est celui déclaré par la clinique. » (D-004) ;
- les observations.

**Ordonnance** :

- date de signature et référence courte ;
- prescripteur : nom, profession, spécialité, numéro d'inscription s'il est renseigné ;
- patient : nom, sexe, âge à la date de signature, dossier ;
- médicaments numérotés : dosage, forme, posologie, fréquence, durée, voie, quantité, consignes ;
- recommandations ;
- encadré « Signée électroniquement le … par … » ;
- référence complète en pied de page, qui permet de vérifier l'ordonnance dans le dossier.

L'ordonnance imprimée ne contient aucune posologie calculée par le système : le contenu est celui validé par le prescripteur.

## 5. Traçabilité

Chaque export est enregistré dans le journal d'audit :

- `INVOICE_EXPORTED` pour les factures ;
- `PRESCRIPTION_EXPORTED` pour les ordonnances, avec l'origine de la demande (`staff` ou `patient`).

## 6. Interface web

- Le fichier est téléchargé par `download()` (`js/core/api.js`). Le jeton est envoyé dans l'en-tête `Authorization`, jamais dans l'URL. La session est renouvelée si le jeton a expiré.
- Les boutons se trouvent sur la fiche de facture (« Aperçu PDF » pour un brouillon, « Télécharger le PDF » sinon), dans la liste des factures, et sur chaque ordonnance signée de la consultation.

## 7. Tests

`tests/Api/DocumentsPdfTest.php` couvre :

- la structure du fichier : table des références croisées, codage WinAnsi, échappement des caractères, zéro bien écrit ;
- la mesure du texte et les retours à la ligne ;
- la facture : brouillon puis émise, droits, en-tête paramétrable, audit ;
- l'accès du patient, limité aux factures émises de son propre dossier ;
- l'ordonnance : signée seulement, droits médicaux exigés, contenu du document, audit, accès du patient.
