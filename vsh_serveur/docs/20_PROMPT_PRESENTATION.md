# Prompt pour Claude dans PowerPoint : présentation du projet

Copier tout le bloc ci-dessous dans Claude, dans PowerPoint. Les montants sont une **estimation indicative** à ajuster avant diffusion : ils s'appuient sur la répartition détaillée dans la présentation.

```text
Crée une présentation PowerPoint professionnelle, en français, de 12 diapositives au maximum, pour présenter le projet « VISION HOMECARE » à la direction d'une clinique et à ses partenaires financiers au Niger. Ton : sobre, clair, orienté décision. Pas de jargon technique inutile : les détails techniques restent dans une seule diapositive.

IDENTITÉ VISUELLE
- Couleurs du logo : orange #E8572A (accent, parcimonie) et vert #1E9A3C ; vert foncé #15803D pour les titres et éléments principaux ; fond clair #F5F8F6 ; texte #14201A ; gris secondaire #4B5B53.
- Polices : Figtree pour les titres, Noto Sans pour le texte (sinon une sans-serif équivalente).
- Style : aéré, icônes au trait, un message clé par diapositive, au plus 5 puces par diapositive, schémas simples plutôt que du texte. Pas de photos de patients réels, pas de faux témoignages, pas de statistiques inventées.
- Pied de page discret : « Vision Homecare — Présentation du projet » et numéro de diapositive.

CONTEXTE DU PROJET (à utiliser comme source, ne rien inventer au-delà)
VISION HOMECARE est une solution de gestion pour une clinique qui fait des consultations et des soins sur place et à domicile, au Niger (Niamey). Elle comprend :
1. Une plateforme serveur sécurisée (API REST PHP, base MySQL) : dossiers patients, consultations, constantes, soins, examens avec validation médicale, ordonnances signées, rendez-vous, visites à domicile, facturation, journal d'audit, documents PDF (factures, ordonnances) avec l'en-tête de la clinique.
2. Une interface web pour le personnel : accueil (recherche de patient au fil de la frappe), régulation des visites à domicile sur carte (OpenStreetMap), consultations, laboratoire, facturation, administration (utilisateurs, rôles et droits, équipes, référentiels, tarifs versionnés, paramètres, journal d'audit, supervision des envois de notifications).
3. Une application mobile Android/iOS (Flutter), UNE SEULE application pour deux publics, chacun dans son espace :
   a. Les équipes de terrain, SANS RÉSEAU (« Offline-First ») : tournée à domicile (prise en charge, départ, arrivée avec GPS, soins, constantes, fin de visite), soins à faire, agenda du jour, dossiers patients. Tout est enregistré sur le téléphone dans une base chiffrée, puis synchronisé automatiquement au retour du réseau, sans perte ni doublon. Un indicateur visible montre l'état : vert « Synchronisé », orange « Synchronisation en cours », rouge « Hors connexion ».
      Guidage jusqu'au domicile SANS QUITTER L'APPLICATION : tracé routier sur la carte, consigne suivante en français (« Tournez à gauche sur… »), heure d'arrivée estimée, recalcul automatique en cas d'écart ; cartes des quartiers de la tournée téléchargées à l'avance pour s'afficher même sans réseau chez le patient.
   b. Les patients : connexion par code SMS (sans mot de passe) ou inscription directement dans l'application, demande de rendez-vous sur les créneaux libres, demande de visite à domicile avec position GPS, suivi de la visite étape par étape et, quand l'équipe est en route, sa position sur la carte avec l'heure d'arrivée estimée ; résultats d'examens validés, ordonnances et factures en PDF ; dossiers de toute la famille ; consultation possible hors connexion.
   Chaque utilisateur, quel que soit son profil, dispose d'un menu « Mon compte » pour se déconnecter (avec avertissement si des saisies ne sont pas encore envoyées).
4. Un portail patient web (mobile d'abord) offrant les mêmes services que l'espace patient de l'application, pour ceux qui ne l'installent pas.
5. Des notifications : dans l'application, par notification push (Firebase) et par SMS en repli quand le push n'arrive pas (coût SMS maîtrisé, types de messages choisis par la clinique). Aucun détail médical dans les messages.

Points forts à mettre en valeur :
- Pensé pour les réalités locales : réseau intermittent, déplacements à domicile, repères plutôt qu'adresses, guidage et cartes hors ligne, paiement hors plateforme (espèces, mobile money) enregistré de façon déclarative.
- Prêt pour une grande échelle : testé sur une base d'un million de dossiers patients ; recherche d'un patient en quelques millisecondes ; chaque téléphone patient ne lit que ses propres dossiers, quelle que soit la taille de la base.
- Sécurité et confidentialité : données médicales visibles seulement par les soignants autorisés, droits vérifiés côté serveur, aucune donnée médicale dans les notifications ni dans les adresses web, base mobile chiffrée, appareil perdu révocable à distance (effacement des données), traçabilité complète (journal d'audit), aucun prix ni droit écrit dans le code (tout est paramétrable).
- Aucune logique clinique arbitraire : le logiciel enregistre et trace, le professionnel décide (validation des examens par un médecin, signature des ordonnances).
- Vie privée du personnel : position GPS collectée seulement pendant une visite en cours, et montrée au patient uniquement pendant le trajet vers son propre domicile.
- Qualité : plus de 200 tests automatisés côté serveur et plus de 60 côté mobile, dont les scénarios de coupure réseau ; parcours complets vérifiés de bout en bout.

PLAN DES DIAPOSITIVES (12 au maximum)
1. Titre : « VISION HOMECARE — Des soins à domicile connectés, même sans réseau ». Sous-titre : plateforme de gestion clinique et soins à domicile, Niamey, Niger. Espace réservé pour le logo.
2. Le besoin : les difficultés actuelles (dossiers papier, coordination des visites à domicile, réseau instable, traçabilité et facturation des soins, information du patient). Présenter en 4 cartes « problème → réponse ».
3. La solution en un coup d'œil : schéma de la plateforme centrale (API sécurisée et base de données) reliée à l'interface web du personnel, à l'application mobile (espace équipes et espace patient) et au portail patient web ; les notifications (push et SMS) partent de la plateforme.
4. Le parcours de bout en bout (frise horizontale en 7 étapes) : le patient demande une visite depuis son téléphone → la régulation affecte une équipe (carte) → l'équipe est guidée jusqu'au domicile dans l'application, pendant que le patient voit son approche et l'heure d'arrivée → l'infirmière réalise la visite, même hors ligne (GPS, constantes, soins) → le médecin complète la consultation et signe l'ordonnance → l'accueil émet la facture (PDF) → le patient est prévenu et consulte ses documents et résultats.
5. Le terrain sans réseau : saisie sur le téléphone, file d'envoi, synchronisation automatique, indicateur vert/orange/rouge, guidage et cartes hors ligne. Message clé : « Zéro perte de données, zéro doublon ».
6. L'application côté patient : 4 écrans simples (Accueil, Rendez-vous, À domicile, Mon dossier) ; inscription ou connexion par code SMS ; suivi de l'équipe en route ; documents PDF ; toute la famille dans un seul compte. Message clé : « Le patient suit ses soins depuis son téléphone ».
7. Notifications et coûts maîtrisés : notification push gratuite, SMS seulement en repli et pour les messages choisis par la clinique, rien d'envoyé pour un message déjà lu ; aucun détail médical dans les messages.
8. Sécurité et confidentialité des données de santé : 5 garanties (voir points forts), sous forme d'icônes.
9. Architecture, technologies et montée en charge (une seule diapositive technique) : serveur PHP 7.3+ et MySQL/MariaDB, sans dépendance lourde ; interface web légère ; application Flutter (Android/iOS) avec base locale Drift/SQLite chiffrée ; synchronisation idempotente avec gestion des conflits champ par champ ; cartes et itinéraires OpenStreetMap ; hébergement HTTPS avec sauvegardes quotidiennes. Encadré « testé sur 1 million de dossiers » : recherche d'un patient en quelques millisecondes (contre plus de 2 secondes avant optimisation).
10. Plan de déploiement progressif (frise en 5 phases, environ 5 mois, avec critère de passage à la phase suivante) :
   - Phase 0 — Préparation (semaines 1 à 3) : serveur HTTPS et sauvegardes, paramétrage, référentiels et tarifs, comptes et rôles, reprise des dossiers existants prioritaires, projet Firebase et passerelle SMS. Critère : environnement validé par la direction.
   - Phase 1 — Pilote en clinique (mois 1) : accueil, dossiers, rendez-vous, consultations, laboratoire, facturation sur un site ; le papier reste en parallèle. Critère : tous les nouveaux dossiers et toutes les factures passent par l'outil.
   - Phase 2 — Pilote à domicile (mois 2) : une équipe mobile à Niamey, régulation sur carte, application mobile hors ligne et guidage intégré. Critère : aucune perte de données, délais de prise en charge mesurés.
   - Phase 3 — Généralisation (mois 3 et 4) : toutes les équipes, ouverture de l'application et du portail aux patients, notifications push et SMS réels, formation de tout le personnel. Critère : fin du double circuit papier.
   - Phase 4 — Consolidation (mois 5 et au-delà) : tableaux de bord et rapports, audit de sécurité, serveurs de cartes et d'itinéraires propres à la clinique, extension à d'autres sites ou villes.
   Ajouter en bas : « À chaque phase : formation, accompagnement sur site, retour arrière possible (circuit papier maintenu pendant les pilotes). »
11. Coût du projet (en francs CFA, hors taxes), sous forme de tableau clair avec total mis en évidence :
   | Poste | Montant (FCFA HT) |
   | Cadrage, analyse, architecture et charte graphique | 1 500 000 |
   | Plateforme serveur sécurisée (API, synchronisation, documents PDF, audit, montée en charge) | 4 500 000 |
   | Interface web du personnel (accueil, régulation et carte, consultations, laboratoire, facturation, administration) | 4 000 000 |
   | Portail patient web (connexion par code SMS) | 1 000 000 |
   | Application mobile hors ligne Android et iOS (espace équipes) | 5 000 000 |
   | Espace patient dans l'application mobile (inscription, rendez-vous, visites, suivi de l'équipe, documents) | 2 000 000 |
   | Notifications push et SMS, guidage intégré et cartes hors ligne | 1 500 000 |
   | Tests, recette et documentation | 1 500 000 |
   | Sous-total réalisation | 21 000 000 |
   | Mise en production et infrastructure la 1re année (serveur, nom de domaine, certificat HTTPS, sauvegardes externalisées, crédit SMS initial) | 1 500 000 |
   | Formation sur site (accueil, médecins, équipes mobiles, administrateur) | 1 000 000 |
   | Maintenance et support pendant 12 mois (150 000 FCFA par mois) | 1 800 000 |
   | TOTAL PROJET (1re année) | 25 300 000 FCFA HT |
   Sous le tableau, en petit :
   - Options : smartphones Android pour les équipes (environ 120 000 FCFA par appareil) ; comptes de publication Google Play et Apple ; serveurs de cartes et d'itinéraires propres à la clinique (conseillés au-delà du pilote).
   - À partir de la 2e année : environ 3 000 000 FCFA HT par an (hébergement et maintenance), hors SMS consommés.
   - Échéancier proposé : 30 % à la commande, 40 % à la livraison du pilote, 30 % à la mise en production.
   - Mention : « Estimation indicative, à confirmer après validation du périmètre. »
12. Décision attendue et prochaines étapes : validation du périmètre et du budget, désignation d'un référent clinique et d'une équipe pilote, choix de l'hébergement, de la passerelle SMS et création du projet Firebase de la clinique, date de lancement de la phase 0. Terminer par une phrase de conclusion : « Des soins mieux coordonnés, tracés et facturés — au cabinet comme au domicile du patient. »

CONSIGNES DE MISE EN FORME
- Titres courts et affirmatifs, qui disent le message de la diapositive.
- Chiffres en format français (espaces pour les milliers : 25 300 000 FCFA).
- Ajoute des notes de l'orateur (3 à 5 phrases) sous chaque diapositive, pour présenter à l'oral.
- Vérifie qu'aucune diapositive ne dépasse la zone visible et que le contraste du texte est suffisant.
```
