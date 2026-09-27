# VISION HOMECARE

Solution de gestion d'un cabinet / clinique médicale avec consultations et soins en clinique et **à domicile**, équipes mobiles, dossiers patients, examens, ordonnances, rendez-vous, facturation, carte des interventions et application mobile **Offline-First**.

## Structure

| Dossier | Contenu |
|---|---|
| [`vsh_serveur/`](vsh_serveur/) | API REST PHP 7.3+ sans framework (PDO, MariaDB/MySQL), interface web d'administration et portail patient |
| [`vsh_mobile/`](vsh_mobile/) | Application Flutter (Android/iOS) Offline-First : base locale chiffrée, file de synchronisation |

## Démonstration

- [Guide de démonstration de bout en bout](vsh_serveur/docs/19_GUIDE_DEMONSTRATION.md) : comptes de test par acteur, préparation, parcours complet (portail patient → régulation → tournée mobile hors ligne → médecin → laboratoire → facturation).
- [Prompt de présentation PowerPoint](vsh_serveur/docs/20_PROMPT_PRESENTATION.md) : 10 diapositives, dont le coût en FCFA et le plan de déploiement progressif.
- Application mobile en débogage : `cd vsh_mobile && flutter run`. Elle se connecte seule au serveur XAMPP local (`10.0.2.2:8085` depuis l'émulateur Android).

## État du projet

- Phase 1 — Analyse & architecture : [rapport initial](vsh_serveur/docs/00_RAPPORT_INITIAL.md), [journal des décisions](vsh_serveur/docs/01_DECISIONS.md) ✔
- Phase 3 — Base de données : [migrations, seeds, documentation](vsh_serveur/docs/02_BASE_DE_DONNEES.md) ✔
- Étape 2 — Socle backend : [architecture](vsh_serveur/docs/03_SOCLE_BACKEND.md), [installation](vsh_serveur/docs/04_INSTALLATION.md) ✔
- Étape 3 — Authentification, sessions, rôles et permissions, audit : [documentation](vsh_serveur/docs/05_AUTHENTIFICATION_DROITS.md) ✔
- Étape 4 — Référentiels, tarifs, paramètres, dossier patient, inscription et portail patient : [documentation](vsh_serveur/docs/06_REFERENTIELS_PATIENTS.md) ✔
- Étape 5 — Moteur de synchronisation serveur (envoi idempotent, fusion par champ, conflits, récupération incrémentale par périmètre, supervision) : [contrat](vsh_serveur/docs/07_SYNCHRONISATION.md) ✔
- Charte graphique et design system (couleurs du logo, typographie, composants, maquettes web et mobile) : [charte](vsh_serveur/docs/08_CHARTE_GRAPHIQUE.md) ✔
- Étape 6a — Consultations, constantes, diagnostics, notes et soins (synchronisables, actions hors ligne) : [documentation](vsh_serveur/docs/09_CONSULTATIONS_SOINS.md) ✔
- Étape 6b — Examens (résultats structurés, validation D-005) et ordonnances (modèles configurables approuvés, alertes d'allergie, signature) : [documentation](vsh_serveur/docs/10_EXAMENS_ORDONNANCES.md) ✔
- Étape 7 — Équipes (appartenance datée) et visites à domicile (machine à états, auto-attribution et régulation D-003, GPS, consultation à domicile, carte, tournée hors ligne) : [documentation](vsh_serveur/docs/11_VISITES_DOMICILE_EQUIPES.md) ✔
- Étape 8a — Facturation (D-004 : brouillon généré depuis les soins et examens au tarif en vigueur, émission numérotée, état de règlement déclaratif, visite FACTUREE) : [documentation](vsh_serveur/docs/12_FACTURATION.md) ✔
- Étape 8b — Rendez-vous (créneaux calculés par le serveur, demande patient, confirmation, déplacement, annulation, jour J, planning hors ligne) : [documentation](vsh_serveur/docs/13_RENDEZ_VOUS.md) ✔
- Étape 9a — Interface web : connexion, mot de passe imposé ou oublié, tableau de bord selon le rôle, recherche et fiche patient, thèmes clair et sombre, responsive, CSP stricte : [documentation](vsh_serveur/docs/14_INTERFACE_WEB.md) ✔
- Étape 9b-1 — Interface web, accueil : création et modification de patient avec détection des doublons, validation des inscriptions, médecin traitant, contacts et adresses, agenda des rendez-vous (créneaux libres, confirmation, déplacement, annulation, arrivée, absence) ✔ — accès local : `http://localhost:8085/vsh_niger/vsh_serveur/public/`
- Étape 9b-2 — Interface web, soins : consultations (file de travail, observation, constantes, diagnostics CIM-10, soins, examens prescrits, notes et addendums, clôture) et examens (file du technicien, saisie structurée des résultats, validation D-005) ✔
- Étape 9c — Interface web, domicile : file des visites, fiche avec frise et actions selon le rôle (affectation, départ, arrivée, soins, fin, échec, annulation), nouvelle demande préremplie, carte de régulation OpenStreetMap (Leaflet auto-hébergé) ✔
- Géolocalisation renforcée :
  - relevé GPS précis avec affinage ;
  - choix d'une position sur la carte, au GPS ou par coordonnées ;
  - suivi du trajet et partage de position en direct pendant la visite ;
  - contrôle de l'arrivée ;
  - relevé du domicile sur place, reporté dans le dossier ;
  - affectation par proximité ;
  - carte avec filtres, « ma position » et plein écran ;
  - seuils paramétrables et purge des trajets (`geo:purge`) ✔
- Étape 9d — Facturation et documents :
  - suivi des factures et du reste à percevoir ;
  - fiche de facture : lignes aux tarifs en vigueur ou ligne libre, remise, émission, annulation, règlement déclaré ;
  - « Facturer » depuis une consultation ou une visite ;
  - ordonnances dans la consultation : modèle approuvé ou rédaction libre, alertes d'allergie, signature ;
  - **PDF des factures et ordonnances**, générés par un moteur sans dépendance, avec en-tête paramétrable et exports audités ✔
- Étape 9e — Administration :
  - personnel : création avec mot de passe temporaire affiché une seule fois, suspension, réinitialisation ;
  - rôles et permissions ;
  - équipes et appartenances datées ;
  - référentiels (services et plages horaires, actes, soins, examens et paramètres de résultats, médicaments) ;
  - tarifs versionnés ;
  - modèles d'ordonnance, rédigés puis approuvés par un médecin ;
  - paramètres (dont l'en-tête des documents) ;
  - journal d'audit consultable (`GET /audit-logs`) ;
  - supervision de la synchronisation et révocation d'appareils ✔
- Étape 9f — Portail web patient (`public/app/portail.html`) :
  - connexion par code SMS sans mot de passe (D-001) ;
  - dossiers de la famille ;
  - rendez-vous (créneaux libres, annulation) et visites à domicile avec position GPS ;
  - résultats validés ;
  - ordonnances et factures en PDF ✔
- Étape 10 — Application mobile Flutter, socle hors ligne : [documentation](vsh_serveur/docs/18_APPLICATION_MOBILE.md)
  - base locale Drift **chiffrée** (SQLite3MultipleCiphers) ;
  - file d'opérations transactionnelle et moteur de synchronisation idempotent (lots, attente progressive, dépendances, conflits, reprise après coupure ou redémarrage) ;
  - connexion liée à l'appareil, renouvellement du jeton, révocation ;
  - pastille 🟢 / 🟠 / 🔴 et centre de synchronisation ;
  - dossiers patients consultables, créés et modifiés hors ligne ;
  - scénarios du cahier des charges testés, contrat vérifié sur le serveur réel ✔
- Étape 11 — Application mobile, tournée à domicile hors ligne : [documentation](vsh_serveur/docs/18_APPLICATION_MOBILE.md)
  - étapes de la visite enchaînées et horodatées à l'heure du serveur ;
  - GPS au départ et à l'arrivée, trajet pendant la visite, carte OpenStreetMap, itinéraire, appel ;
  - consultation à domicile ouverte hors ligne, constantes et notes ;
  - refus et abandons en cascade, état du serveur mis de côté ;
  - tournée complète vérifiée sur le serveur réel ✔

- Étape 12 — Application mobile, soins, agenda et dossiers : [documentation](vsh_serveur/docs/18_APPLICATION_MOBILE.md)
  - soins à faire, réalisés ou annulés hors ligne, et soins réalisés pendant la visite ;
  - agenda du jour (arrivée, absence) ;
  - notifications lues hors ligne ;
  - référentiels et paramètres de la clinique gardés sur le téléphone ;
  - recherche serveur et épinglage de dossiers, fiche patient enrichie ✔

- Étape 13 — Notifications push (FCM) et SMS : [documentation](vsh_serveur/docs/21_NOTIFICATIONS.md), décision D-011
  - boîte d'envoi transactionnelle traitée par `notifications:dispatch` (cron chaque minute), nouvelles tentatives puis abandon ;
  - push FCM HTTP v1 sans dépendance, jeton lié à l'appareil, supprimé à la déconnexion ou s'il est invalide ;
  - SMS aux patients en repli quand le push n'aboutit pas, types choisis par l'administrateur ;
  - rien d'envoyé pour une notification déjà lue ou trop ancienne, supervision sans contenu ;
  - mobile : alertes locales à l'arrivée par synchronisation (sans push), FCM facultatif configuré à la compilation ✔

- Espace patient dans l'application mobile : [documentation](vsh_serveur/docs/18_APPLICATION_MOBILE.md) §11
  - une application pour le personnel et pour les patients : connexion par code SMS liée au téléphone ;
  - rendez-vous (créneaux libres, annulation), visites à domicile (suivi par étapes, GPS) ;
  - résultats validés, ordonnances et factures en PDF, dossiers de la famille, consultation hors ligne ✔
- Montée en charge (plusieurs millions de dossiers) : [analyse mesurée et plan d'action](vsh_serveur/docs/22_MONTEE_EN_CHARGE.md)

### Suite de la feuille de route

| Étape | Contenu |
|---|---|
| 14 | Durcissement, tableau de bord, rapports, mise en production |

L'API REST est construite et testée en premier : l'interface web et l'application mobile reposent entièrement sur elle, avec les mêmes règles, droits et validations.

Les instructions d'installation et de déploiement seront ajoutées au fur et à mesure dans `vsh_serveur/docs/`.
