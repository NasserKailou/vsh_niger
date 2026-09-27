# Interface web (étape 9)

## 1. Accès

| Adresse | Contenu |
|---|---|
| `…/vsh_serveur/public/` | redirige vers l'interface web |
| `…/vsh_serveur/public/app/` | interface web du personnel |
| `…/vsh_serveur/public/api/v1/…` | API REST (inchangée) |
| `…/vsh_serveur/` | **403 volontaire** : la racine contient `.env`, la configuration et les données |

En local : `http://localhost:8085/vsh_niger/vsh_serveur/public/`.

En production, le site pointe directement sur `vsh_serveur/public`, et l'interface est alors à `https://<domaine>/app/`.

**Premier compte** : `php bin/console.php create-admin --first-name=… --last-name=… --phone=+227…`. Le mot de passe provisoire n'est affiché qu'une fois, et il doit être remplacé à la première connexion.

## 2. Architecture

- **Application légère** en **JavaScript natif (modules ES), sans outil de compilation** : aucune dépendance à installer, et un fonctionnement identique sous XAMPP et en production.
- **Consommation de l'API REST uniquement** : les droits affichés ne sont qu'un confort d'affichage, car le serveur revérifie chaque requête.

```
public/app/
├── index.html          point d'entrée (aucun script en ligne)
├── .htaccess           en-têtes de sécurité (CSP stricte, anti-iframe, no-referrer)
├── css/app.css         gabarit et écrans, uniquement les jetons --vsh-* de la charte
└── js/
    ├── main.js         routage, garde de session, changement de mot de passe imposé
    ├── shell.js        barre latérale (menu filtré par droits), barre supérieure, thème
    ├── ui.js           composants : champs, erreurs, tableaux, onglets, états vides
    ├── core/           dom (sans innerHTML), api, session, router, format, icons
    └── views/          auth, dashboard, patients, patient, soon
public/assets/
├── css/vsh.css         design system (charte : 08_CHARTE_GRAPHIQUE.md)
├── css/fonts.css       polices auto-hébergées
└── fonts/              Figtree et Noto Sans (licence OFL) : aucun appel à Google
```

## 3. Sécurité

- **Politique CSP** : `default-src 'self'`, sans script ni style en ligne ou externe. Les tuiles OpenStreetMap sont la seule exception prévue (carte, bloc 9c).
- **Pages construites sans `innerHTML`** : les données passent par `textContent` ou `setAttribute`, ce qui empêche l'injection de code (XSS).
- **Session** :
  - le **jeton d'accès reste en mémoire** ;
  - le **jeton de renouvellement est gardé dans `sessionStorage`**, limité à l'onglet. Il change à chaque usage, et une réutilisation est détectée par le serveur ;
  - un 401 déclenche **un seul** renouvellement, mutualisé entre les appels simultanés, puis la requête est rejouée. En cas d'échec, l'écran de connexion s'affiche avec « Votre session a expiré ».
- **Comptes patients** : ils sont refusés sur cette interface. Le portail patient aura son propre espace.
- **`localStorage`** : il ne contient que la préférence de thème.

## 4. Choix UX/UI

Ces choix ont été validés avec la compétence *ui-ux-pro-max* : style minimaliste de type suisse, densité « tableau de bord », mouvements discrets.

- **Charte** : vert et orange du logo, Figtree pour les titres, Noto Sans pour le texte. Contraste AA vérifié, thèmes clair et sombre.
- **Accessibilité**
  - lien « Aller au contenu » et régions nommées ;
  - focus toujours visible, et focus déplacé sur le titre à chaque changement de page ;
  - libellés visibles ; erreurs sous chaque champ (`aria-describedby`) et résumé d'erreurs focalisable ;
  - onglets conformes WAI-ARIA, pilotables au clavier (flèches, Début, Fin) ;
  - statuts affichés avec couleur **et** libellé ;
  - icônes SVG, jamais d'emoji ;
  - cibles tactiles d'au moins 44 px ;
  - `prefers-reduced-motion` respecté.
- **Responsive** : testé à 1366 et 390 px, sans défilement horizontal de la page. Le menu devient un tiroir (Échap pour fermer), et les tableaux défilent dans leur cadre.
- **États** :
  - squelettes de chargement ;
  - états vides explicites ;
  - erreurs avec « Réessayer » ;
  - indicateur « Hors connexion ».
- **Liens profonds** : filtres et onglets figurent dans l'URL (`#/patients?term=…`), ce qui fait fonctionner le bouton « Retour ».

## 5. Écrans du bloc 9a

| Écran | Contenu |
|---|---|
| Connexion | Téléphone et mot de passe, afficher / masquer, erreurs, session expirée |
| Mot de passe oublié | Code SMS, puis nouveau mot de passe (règles affichées) |
| Mot de passe imposé | Remplacement du mot de passe provisoire avant tout accès |
| Tableau de bord | Indicateurs selon le rôle (`GET /dashboard`), planning du jour du praticien, répartition des visites à domicile et des rendez-vous |
| Patients | Un champ unique qui reconnaît nom, n° de dossier ou téléphone, plus date de naissance et statut ; onglet « Inscriptions à valider » avec contrôle des doublons |
| Fiche patient | Identité, personnes à prévenir, adresses ; **dossier médical seulement pour les personnes autorisées** (D-010, consultation tracée) ; consultations, rendez-vous, factures selon les droits |
| Écrans « bientôt » | Menu complet dès maintenant ; chaque écran à venir indique son bloc et son contenu |

### API ajoutée : `GET /dashboard`

- **Contenu** : compteurs agrégés **sans contenu médical**.
- **Blocs** : chacun n'apparaît que si l'utilisateur a la permission de son domaine :
  - `activity` et `registrations_pending` ;
  - `appointments_today` (accueil) et `my_agenda` (praticien seulement) ;
  - `homecare` (régulation) et `my_team_visits` (équipes) ;
  - `exam_queue` et `exams_to_validate` ;
  - `billing_month`.
- **Accès** : refusé aux comptes patients (403). Il faut `dashboard.global` ou `dashboard.personal`.
- **Tests** : `tests/Api/DashboardTest.php`.

## 6. Écrans du bloc 9b-1 (accueil)

| Écran | Contenu |
|---|---|
| Nouveau patient (`#/patients/new`) | Identité (sexe en choix explicite, date approximative), personne à prévenir, adresse avec repère, médecin traitant. **Doublon possible** : boîte listant les dossiers semblables, avec « Ouvrir » ou « C’est une autre personne » (`confirm_not_duplicate`) |
| Modifier (`#/patients/{id}/edit`) | Identité, avec contrôle de version : une modification concurrente est signalée au lieu d’être écrasée |
| Fiche patient | **Valider ou rejeter** une inscription (motif obligatoire), **médecin traitant**, **ajouter ou retirer** personnes à prévenir et adresses (confirmation), bouton « Rendez-vous » |
| Rendez-vous (`#/appointments`) | **Agenda** du jour (navigation jour par jour, filtres service et statut), onglet **Demandes à confirmer** (application patient), **prise de rendez-vous** : patient (recherche au clavier), service, date, **grille des créneaux libres** calculée par le serveur, praticien, motif. Actions : confirmer (attribution du praticien), déplacer, annuler (motif), arrivée, absent. Un praticien ne voit que son planning, sans actions |

**Composants ajoutés**
- Boîte de dialogue sur `<dialog>` natif : focus piégé, Échap, focus rendu au déclencheur.
- Demande de motif, confirmation d’action, formulaire court.
- Sélecteur de patient en combobox WAI-ARIA (flèches, Entrée, Échap), avec nombre de résultats annoncé.
- Grille de créneaux : boutons `aria-pressed`, créneaux complets désactivés et annoncés « complet ».

### API ajoutée : `GET /staff/directory?profession=`

- **Contenu** : annuaire minimal des soignants actifs (identifiant, nom, profession, spécialité), **sans téléphone ni rôle d’accès**. Il alimente les listes « praticien » et « médecin traitant ».
- **Accès** : réservé à ceux qui affectent du personnel (`appointments.manage`, `patients.assign_attending`, `patients.create`, `homecare.dispatch`, `teams.manage`, `users.read`).
- **Tests** : `tests/Api/StaffDirectoryTest.php`.

## 7. Écrans du bloc 9b-2 (soins)

| Écran | Contenu |
|---|---|
| Consultations (`#/consultations`) | File de travail : **en cours**, **aujourd’hui**, **clôturées**, avec « seulement les miennes » pour les praticiens. **Nouvelle consultation** : patient, type (clinique, suivi avec la consultation d’origine, urgence), motif. Depuis l’agenda, le bouton **« Consulter »** sur un patient arrivé ouvre la consultation liée, et le rendez-vous passe à *honoré* |
| Espace de consultation (`#/consultations/{id}`) | **Allergies connues** rappelées en tête. **Observation** (motif, anamnèse, examen, conclusion) avec indicateur « modifications non enregistrées » et contrôle de version. **Constantes** (unités, IMC). **Diagnostics** (CIM-10, principal ou secondaire, certitude). **Soins** (réalisé ou programmé). **Examens** prescrits (priorité, renseignements cliniques). **Notes**, qui deviennent des **addendums** après la clôture. **Clôturer** ou annuler (motif). Sans droits médicaux : métadonnées seulement (D-010) |
| Examens (`#/examinations`) | Onglets selon le rôle : **À réaliser** (technicien, urgents en tête), **À valider** (médecin), Tous |
| Fiche d’examen (`#/examinations/{id}`) | Saisie **structurée** selon les paramètres configurés : valeur numérique avec unité et référence, choix, texte ; résultats libres ; commentaire. Erreurs affichées sous chaque paramètre. **Terminer**, puis **valider** (D-005, jamais par la personne qui a saisi). Marqueur **« hors valeurs de référence »**, informatif, sans interprétation |

**Évolution de l’API** : les listes de consultations renvoient `patient: {id, file_number, name}`, l’identité minimale sans contenu clinique (`tests/Api/ConsultationListTest.php`).

## 8. Écrans du bloc 9c (domicile et carte)

| Écran | Contenu |
|---|---|
| Visites à domicile (`#/homecare`) | Onglets **À traiter** (régulation : nouvelles et en attente ; équipes : en attente), **En cours** (avec « seulement mon équipe »), **Terminées**. Urgences en tête, temps d’attente affiché. **Nouvelle demande** : patient, motif, urgence, heure souhaitée ; téléphone, adresse, repère et position GPS **préremplis depuis le dossier** |
| Fiche de visite (`#/homecare/{id}`) | Frise des six étapes. Actions **selon le rôle et l’état**, calculées côté serveur puis reproduites à l’écran : valider, affecter ou réaffecter une équipe mobile (régulation) ; prendre en charge, se désister, **départ**, **arrivée**, **démarrer les soins** (ouvre la consultation à domicile), terminer, échec (membres de l’équipe) ; annuler (motif obligatoire). Au départ et à l’arrivée, la **position de l’appareil** est relevée et affichée avant la confirmation (voir la section 9). Carte de suivi, historique horodaté (« saisi hors ligne » quand la réception est tardive) |
| Carte (`#/map`) | Leaflet 1.9.4 auto-hébergé, tuiles OpenStreetMap. Visites ouvertes colorées par état (à affecter / équipe engagée), urgences avec un contour épais, **dernière position des équipes** reliée au domicile. Indicateurs (ouvertes, à affecter, urgentes), liste des visites **sans GPS**. Actualisation toutes les 60 s tant que l’écran est affiché, suspendue quand l’onglet est masqué |

**Confidentialité** : la carte ne montre que le numéro de dossier, l’état et l’adresse ou le repère, sans nom ni motif (réponse de `GET /map/homecare`). Les infobulles sont construites en DOM (`textContent`) et ne reçoivent jamais de chaîne HTML. Les URL de tuiles ne contiennent aucune donnée patient, et l’en-tête Referer est limité à l’origine.

**CSP** : aucun attribut `style` en ligne. Les couleurs calculées, comme celles de la légende, sont appliquées par le CSSOM (`element.style`), que la CSP autorise.

## 9. Géolocalisation renforcée

Modules : `js/geo.js` (acquisition, distances, liens, partage), `js/locationPicker.js` (choix d’une position), `js/views/homecareGeo.js` (fiche de visite). API et paramètres : voir [11_VISITES_DOMICILE_EQUIPES.md](11_VISITES_DOMICILE_EQUIPES.md), section 3.

| Fonction | Détail |
|---|---|
| **Acquisition précise** | Le premier relevé d’un GPS est souvent grossier : plusieurs relevés sont suivis et le plus précis est retenu, jusqu’à la précision visée ou un délai maximal. La précision est affichée en direct (« Affinage… ± 45 m »), avec un niveau : excellente, bonne, moyenne ou imprécise. Les erreurs sont explicites : autorisation refusée, connexion non sécurisée (HTTPS obligatoire), GPS indisponible, délai dépassé. |
| **Étapes de terrain** | Départ et arrivée : la position est relevée pendant la lecture de la confirmation. En cas d’échec, on peut **réessayer**, ou **continuer sans position** : le GPS ne bloque jamais le soin. |
| **Choix d’une position** | Utilisé pour la nouvelle demande, pour corriger ou relever le domicile, et pour les adresses du patient (ajout, « Localiser », création du dossier). Trois moyens : le GPS de l’appareil, un clic ou un glisser de l’épingle sur la carte, ou la **saisie des coordonnées**, alternative au glisser pour le clavier (WCAG 2.5.7), qui accepte « 13.5137, 2.1098 » comme « 13,5137 2,1098 ». |
| **Affectation par proximité** | Liste des équipes mobiles avec leur distance au domicile, l’âge de leur position, leurs membres et leurs visites en cours. Le badge « La plus proche » est une indication : la régulation garde le choix. |
| **Carte de suivi (fiche)** | Domicile avec précision et **rayon d’arrivée** en pointillés, trajet de l’équipe (les prises en charge abandonnées en gris), points de départ et d’arrivée, dernière position (pulsation ; en gris si ancienne). Indicateurs : distance parcourue, distance de l’équipe au domicile, contrôle d’arrivée. Actualisation automatique pendant la visite. Boutons **Itinéraire** (OpenStreetMap), **Application de navigation** (lien `geo:` sur téléphone), **Copier les coordonnées**, tout afficher, plein écran. |
| **Alertes** | Arrivée loin du domicile, position du domicile imprécise, position d’équipe ancienne (régulation). Ce sont des informations, sans blocage. |
| **Partage en direct** | Démarre après le départ et continue pendant la consultation à domicile. Les points sont envoyés toutes les `geo.track_interval_seconds` secondes. Les petits déplacements très rapprochés sont filtrés, pour économiser les données mobiles et la batterie. Hors connexion, les points sont gardés puis renvoyés (le serveur dédoublonne). L’écran est maintenu allumé si le navigateur le permet (Wake Lock). Le partage reprend après un rechargement de la page. Les derniers points partent **avant** la fin, l’échec, le désistement ou l’annulation, puis le partage s’arrête. Un **indicateur permanent** (« Position partagée » avec un bouton « Arrêter ») reste visible sur tous les écrans, et la déconnexion arrête le partage. |
| **Relevé du domicile sur place** | Pour l’équipe arrivée : relevé précis et option « enregistrer aussi dans le dossier du patient », pour que les prochaines visites soient localisées d’emblée. |
| **Carte de régulation** | Filtres (toutes, à affecter, équipe engagée ; urgentes seulement ; équipe). Épingles colorées, les urgences au premier plan. Cercle de précision des domiciles et des équipes imprécis. Positions anciennes signalées. Commandes **Ma position**, **Tout afficher** et **Plein écran** (Échap pour sortir). Liste des visites localisées avec un bouton **Centrer**, alternative clavier à la carte. |

**Limite connue** : dans un navigateur, la position n’est envoyée que tant que la page reste ouverte. Le suivi en arrière-plan, écran éteint, relève de l’application mobile (étapes 10 à 12).

## 10. Écrans du bloc 9d (facturation et documents)

| Écran | Contenu |
|---|---|
| Facturation (`#/billing`) | **Synthèse** : factures émises, montant net, règlements déclarés, reste à percevoir. **Filtres** : période, n° de facture, état du règlement. Onglets **Émises**, **Brouillons**, **Annulées**, **Toutes**. PDF depuis chaque ligne. **Nouvelle facture** : patient, puis brouillon vide. |
| Fiche de facture (`#/billing/{id}`) | Prestations, avec le type d’origine de chaque ligne. **Ajout de ligne** : acte ou médicament cherché dans le référentiel, avec le **tarif en vigueur affiché avant l’ajout** (et un avertissement s’il n’y en a pas), ou ligne libre avec son prix. Retrait de ligne, remise (au plus le total), observations. **Émettre** : confirmation rappelant le net à payer, attribution du numéro. **Annuler** : motif obligatoire. **Déclarer le règlement** : non réglée, partiellement réglée (montant) ou réglée ; déclaratif (D-004). Totaux, reste dû, historique, PDF (« Aperçu » pour un brouillon). Les prestations sans tarif, non reprises à la création, sont listées. |
| « Facturer » | Depuis une consultation **clôturée** et depuis une visite à domicile **terminée** : brouillon avec reprise automatique des soins et examens réalisés au tarif en vigueur. L’émission passe la visite à « facturée ». |
| Ordonnances (fiche de consultation) | **Rédiger** : ordonnance libre, ou à partir d’un **modèle approuvé** dont les lignes sont copiées puis modifiables. Suggestions du référentiel des médicaments (la DCI dans le libellé ; dosage, forme et voie préremplis) ; saisie libre possible. Erreurs affichées sur le champ de la bonne ligne. **Alertes d’allergie** affichées sans bloquer : le prescripteur décide. Modifier (brouillon), **Signer**, Annuler (motif), **PDF** de l’ordonnance signée. Aucune posologie n’est proposée par l’interface. |

Détails des documents PDF : [15_DOCUMENTS_PDF.md](15_DOCUMENTS_PDF.md).

## 11. Blocs suivants

| Bloc | Écrans |
|---|---|
| — | Interface web terminée : suite avec l’application mobile Flutter (étapes 10 à 12) |

Le bloc 9e (administration : utilisateurs et rôles, équipes, référentiels et tarifs, modèles d’ordonnance, paramètres, journal d’audit, supervision de la synchronisation) est décrit dans [16_ADMINISTRATION.md](16_ADMINISTRATION.md), le bloc 9f (portail patient) dans [17_PORTAIL_PATIENT.md](17_PORTAIL_PATIENT.md).

## 12. Vérifications effectuées

- Syntaxe des modules et cohérence imports / exports : contrôle automatique.
- Navigateur (Chrome) :
  - connexion en erreur ;
  - changement de mot de passe imposé : confirmation différente refusée, puis succès ;
  - tableau de bord, recherche, fiche patient ;
  - thème sombre, affichage à 390 px, tiroir de menu ;
  - **console sans erreur ni violation de la CSP**.
- Navigateur, bloc 9b-1 :
  - rendez-vous : prise complète (recherche du patient au clavier, créneaux, praticien), puis annulation (motif vide refusé) ;
  - patient : détection de doublon ;
  - fiche : médecin traitant, ajout de contact (téléphone invalide refusé par le serveur, puis accepté), rendez-vous depuis la fiche avec patient présélectionné.
- Navigateur, bloc 9b-2, avec trois sessions distinctes (administration, médecin, technicien) :
  - le médecin ouvre une consultation, enregistre l’observation, saisit des constantes (température impossible refusée puis corrigée), pose un diagnostic CIM-10 et prescrit un examen urgent ;
  - le technicien voit l’examen en tête de sa file, saisit les résultats (valeur hors référence signalée) et termine l’examen, qu’il ne peut plus modifier ni valider ;
  - le médecin valide depuis « À valider », clôture la consultation (observation verrouillée) et ajoute un addendum.
- Navigateur, bloc 9c, avec deux sessions (administration, infirmière membre d’une équipe mobile) :
  - la demande urgente est créée depuis la liste (téléphone prérempli depuis le dossier), puis affectée à l’équipe avec une consigne ;
  - l’infirmière la voit dans « En cours », enregistre le départ et l’arrivée (sans réponse à la demande de géolocalisation, l’action part après 8 s), démarre les soins, ce qui ouvre la consultation à domicile, puis termine la visite ; la frise et l’historique sont complets ;
  - carte : tuiles chargées, marqueurs, infobulle de l’équipe, popup sans donnée médicale, visites sans GPS listées ;
  - annulation : motif vide refusé, puis accepté ;
  - affichage à 390 px sans défilement horizontal ; console sans erreur ni violation de la CSP.
- Navigateur, géolocalisation renforcée : la géolocalisation de l’infirmière est simulée pour la session de test ; le trajet est simulé sur Niamey.
  - nouvelle demande avec coordonnées saisies à la virgule française ;
  - affectation : liste des équipes, choix obligatoire ;
  - départ : affinage de ± 60 m à ± 20 m, puis partage démarré automatiquement ; 13 points envoyés ;
  - fiche de la régulation : trajet tracé, distance parcourue d’environ 3,2 km, équipe à 60 m du domicile ;
  - arrivée contrôlée (60 m, dans le rayon) ;
  - relevé du domicile sur place à ± 6 m, enregistré aussi dans l’adresse du dossier ;
  - carte : filtres, « Centrer », plein écran et Échap ;
  - le partage se poursuit pendant la consultation et s’arrête à la fin de la visite, stockage local compris ;
  - « Localiser » une adresse à 390 px : dialogue prérempli, sans défilement horizontal ;
  - console sans erreur applicative. La seule violation de CSP observée vient d’un script injecté par un antivirus, et elle est bloquée comme prévu.
- Navigateur, bloc 9d, avec deux sessions (administration, médecin) :
  - facturation : synthèse et liste ; déclaration d’un règlement partiel (montant incohérent refusé, puis 5 000 FCFA acceptés, reste dû recalculé, historique avec le commentaire) ;
  - nouvelle facture : ligne libre sans prix refusée, puis ajoutée ; remise ; émission avec numéro ; PDF téléchargé ;
  - ordonnance : suggestion du référentiel, erreur sur la bonne ligne, modification, signature, PDF téléchargé ;
  - « Facturer » depuis la consultation clôturée ;
  - affichage à 390 px sans défilement horizontal de la page ; console sans erreur, en dehors des 422 volontaires ;
  - PDF de facture et d’ordonnance ouverts dans le lecteur de Chrome : mise en page vérifiée.
- Apache : redirection de la racine, en-têtes de sécurité, polices et scripts servis avec le bon type.
