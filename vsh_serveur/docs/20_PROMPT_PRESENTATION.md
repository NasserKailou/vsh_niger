# Prompt pour Claude dans PowerPoint : présentation du projet

Copier tout le bloc ci-dessous dans Claude, dans PowerPoint. Les montants sont une **estimation indicative** à ajuster avant diffusion : ils s'appuient sur la répartition détaillée dans la présentation.

```text
Crée une présentation PowerPoint professionnelle, en français, de 10 diapositives au maximum, pour présenter le projet « VISION HOMECARE » à la direction d'une clinique et à ses partenaires financiers au Niger. Ton : sobre, clair, orienté décision. Pas de jargon technique inutile : les détails techniques restent dans une seule diapositive.

IDENTITÉ VISUELLE
- Couleurs du logo : orange #E8572A (accent, parcimonie) et vert #1E9A3C ; vert foncé #15803D pour les titres et éléments principaux ; fond clair #F5F8F6 ; texte #14201A ; gris secondaire #4B5B53.
- Polices : Figtree pour les titres, Noto Sans pour le texte (sinon une sans-serif équivalente).
- Style : aéré, icônes au trait, un message clé par diapositive, au plus 5 puces par diapositive, schémas simples plutôt que du texte. Pas de photos de patients réels, pas de faux témoignages, pas de statistiques inventées.
- Pied de page discret : « Vision Homecare — Présentation du projet » et numéro de diapositive.

CONTEXTE DU PROJET (à utiliser comme source, ne rien inventer au-delà)
VISION HOMECARE est une solution de gestion pour une clinique qui fait des consultations et des soins sur place et à domicile, au Niger (Niamey). Elle comprend :
1. Une plateforme serveur sécurisée (API REST PHP, base MySQL) : dossiers patients, consultations, constantes, soins, examens avec validation médicale, ordonnances signées, rendez-vous, visites à domicile, facturation, journal d'audit, documents PDF (factures, ordonnances) avec l'en-tête de la clinique.
2. Une interface web pour le personnel : accueil, régulation des visites à domicile sur carte (OpenStreetMap), consultations, laboratoire, facturation, administration (utilisateurs, rôles et droits, équipes, référentiels, tarifs versionnés, paramètres, journal d'audit).
3. Une application mobile Android/iOS (Flutter) pour les équipes de terrain, qui fonctionne SANS RÉSEAU (« Offline-First ») : tournée à domicile (prise en charge, départ, arrivée avec GPS, soins, constantes, fin de visite), soins à faire, agenda du jour, dossiers patients. Tout est enregistré sur le téléphone dans une base chiffrée, puis synchronisé automatiquement au retour du réseau, sans perte ni doublon. Un indicateur visible montre l'état : vert « Synchronisé », orange « Synchronisation en cours », rouge « Hors connexion ».
4. Un portail patient web (mobile d'abord) : connexion par code SMS sans mot de passe, demande de rendez-vous et de visite à domicile avec position GPS, suivi de la visite, résultats validés, ordonnances et factures en PDF, dossiers de la famille.

Points forts à mettre en valeur :
- Pensé pour les réalités locales : réseau intermittent, déplacements à domicile, repères plutôt qu'adresses, paiement hors plateforme (espèces, mobile money) enregistré de façon déclarative.
- Sécurité et confidentialité : données médicales visibles seulement par les soignants autorisés, droits vérifiés côté serveur, aucune donnée médicale dans les notifications ni dans les adresses web, base mobile chiffrée, appareil perdu révocable à distance (effacement des données), traçabilité complète (journal d'audit), aucun prix ni droit écrit dans le code (tout est paramétrable).
- Aucune logique clinique arbitraire : le logiciel enregistre et trace, le professionnel décide (validation des examens par un médecin, signature des ordonnances).
- Vie privée du personnel : position GPS collectée seulement pendant une visite en cours.
- Qualité : plus de 180 tests automatisés côté serveur et plus de 40 côté mobile, dont les scénarios de coupure réseau ; parcours complet vérifié de bout en bout.

PLAN DES DIAPOSITIVES (10 au maximum)
1. Titre : « VISION HOMECARE — Des soins à domicile connectés, même sans réseau ». Sous-titre : plateforme de gestion clinique et soins à domicile, Niamey, Niger. Espace réservé pour le logo.
2. Le besoin : les difficultés actuelles (dossiers papier, coordination des visites à domicile, réseau instable, traçabilité et facturation des soins, suivi pour le patient). Présenter en 4 cartes « problème → réponse ».
3. La solution en un coup d'œil : schéma des 4 briques reliées à la plateforme centrale (interface web du personnel, application mobile hors ligne, portail patient, API sécurisée et base de données).
4. Le parcours de bout en bout (frise horizontale en 6 étapes) : le patient demande une visite (portail) → la régulation affecte une équipe (carte) → l'infirmière réalise la visite sur mobile, même hors ligne (GPS, constantes, soins) → le médecin complète la consultation et signe l'ordonnance → l'accueil émet la facture (PDF) → le patient consulte ses documents et résultats.
5. Le terrain sans réseau : comment fonctionne l'application mobile (saisie sur le téléphone, file d'envoi, synchronisation automatique, indicateur vert/orange/rouge). Message clé : « Zéro perte de données, zéro doublon ».
6. Sécurité et confidentialité des données de santé : 5 garanties (voir points forts), sous forme d'icônes.
7. Architecture et technologies (une seule diapositive technique) : serveur PHP 7.3+ et MySQL/MariaDB, sans dépendance lourde ; interface web légère ; application Flutter (Android/iOS) avec base locale Drift/SQLite chiffrée ; synchronisation idempotente avec gestion des conflits champ par champ ; hébergement HTTPS avec sauvegardes quotidiennes.
8. Plan de déploiement progressif (frise en 5 phases, environ 5 mois, avec critère de passage à la phase suivante) :
   - Phase 0 — Préparation (semaines 1 à 3) : serveur HTTPS et sauvegardes, paramétrage, référentiels et tarifs, comptes et rôles, reprise des dossiers existants prioritaires. Critère : environnement validé par la direction.
   - Phase 1 — Pilote en clinique (mois 1) : accueil, dossiers, rendez-vous, consultations, laboratoire, facturation sur un site ; le papier reste en parallèle. Critère : tous les nouveaux dossiers et toutes les factures passent par l'outil.
   - Phase 2 — Pilote à domicile (mois 2) : une équipe mobile à Niamey, régulation sur carte, application mobile hors ligne. Critère : aucune perte de données, délais de prise en charge mesurés.
   - Phase 3 — Généralisation (mois 3 et 4) : toutes les équipes, portail patient et SMS réels, formation de tout le personnel. Critère : fin du double circuit papier.
   - Phase 4 — Consolidation (mois 5 et au-delà) : notifications push, tableaux de bord et rapports, audit de sécurité, extension à d'autres sites ou villes.
   Ajouter en bas : « À chaque phase : formation, accompagnement sur site, retour arrière possible (circuit papier maintenu pendant les pilotes). »
9. Coût du projet (en francs CFA, hors taxes), sous forme de tableau clair avec total mis en évidence :
   | Poste | Montant (FCFA HT) |
   | Cadrage, analyse, architecture et charte graphique | 1 500 000 |
   | Plateforme serveur sécurisée (API, synchronisation, documents PDF, audit) | 4 500 000 |
   | Interface web du personnel (accueil, régulation et carte, consultations, laboratoire, facturation, administration) | 4 000 000 |
   | Portail patient web (connexion par code SMS) | 1 000 000 |
   | Application mobile hors ligne Android et iOS | 5 000 000 |
   | Tests, recette et documentation | 1 500 000 |
   | Sous-total réalisation | 17 500 000 |
   | Mise en production et infrastructure la 1re année (serveur, nom de domaine, certificat HTTPS, sauvegardes externalisées, crédit SMS initial) | 1 500 000 |
   | Formation sur site (accueil, médecins, équipes mobiles, administrateur) | 1 000 000 |
   | Maintenance et support pendant 12 mois (150 000 FCFA par mois) | 1 800 000 |
   | TOTAL PROJET (1re année) | 21 800 000 FCFA HT |
   Sous le tableau, en petit :
   - Options : smartphones Android pour les équipes (environ 120 000 FCFA par appareil) ; comptes de publication Google Play et Apple.
   - À partir de la 2e année : environ 3 000 000 FCFA HT par an (hébergement et maintenance), hors SMS consommés.
   - Échéancier proposé : 30 % à la commande, 40 % à la livraison du pilote, 30 % à la mise en production.
   - Mention : « Estimation indicative, à confirmer après validation du périmètre. »
10. Décision attendue et prochaines étapes : validation du périmètre et du budget, désignation d'un référent clinique et d'une équipe pilote, choix de l'hébergement et de la passerelle SMS, date de lancement de la phase 0. Terminer par une phrase de conclusion : « Des soins mieux coordonnés, tracés et facturés — au cabinet comme au domicile du patient. »

CONSIGNES DE MISE EN FORME
- Titres courts et affirmatifs, qui disent le message de la diapositive.
- Chiffres en format français (espaces pour les milliers : 21 800 000 FCFA).
- Ajoute des notes de l'orateur (3 à 5 phrases) sous chaque diapositive, pour présenter à l'oral.
- Vérifie qu'aucune diapositive ne dépasse la zone visible et que le contraste du texte est suffisant.
```
