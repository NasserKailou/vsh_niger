# Guide de démonstration de bout en bout

Ce guide présente le parcours complet d'une **visite à domicile**, de la demande du patient jusqu'à la facture, en passant par l'application mobile hors ligne. Chaque acteur utilise son propre outil. Durée : environ 45 minutes.

> ⚠️ **Environnement de démonstration uniquement.** Les comptes ci-dessous existent seulement dans la base de développement `vsh_homecare_dev`, qui ne contient que des données fictives. Ils ne doivent jamais être créés en production : là-bas, chaque membre du personnel reçoit un mot de passe temporaire à changer à la première connexion.

## 1. Acteurs et identifiants

| Acteur | Outil | Identifiant (téléphone) | Mot de passe | Rôle dans le parcours |
|---|---|---|---|---|
| Administrateur | Interface web | `+22790000001` | `VisionTest2026` | Paramétrage, référentiels, équipes, journal d'audit |
| Accueil (Mariama) | Interface web | `+22790000005` | `VisionTest2026` | Rendez-vous, régulation des visites, facturation |
| Médecin | Interface web | `+22790000002` | `VisionTest2026` | Consultation, ordonnance signée, prescription et validation d'examens |
| Infirmière (Aïcha), équipe mobile | **Application mobile** | `+22790000004` | `VisionTest2026` | Tournée à domicile hors ligne, GPS, constantes, soins |
| Technicien de laboratoire | Interface web | `+22790000003` | `VisionTest2026` | Saisie des résultats d'examens |
| Patient (Moussa TEST-Carte) | **Portail patient** | Dossier `VSH-2026-000002` + téléphone `+22796445566` | Aucun : **code SMS** | Demande de visite, suivi, résultats, documents PDF |

**Code SMS du patient en démonstration.** Aucun SMS réel n'est envoyé (`SMS_DRIVER=log`). Le code est écrit dans le journal du jour, `vsh_serveur/storage/logs/app-AAAA-MM-JJ.log` (dernière ligne contenant « code »). Une nouvelle demande de code n'est possible qu'après 60 secondes.

## 2. Préparation (une fois, 10 minutes)

1. **Serveur** : démarrer Apache et MySQL de l'instance XAMPP `C:\xamppSites`. Si l'API ne répond pas (erreur réseau), relancer Apache avec `C:\xamppSites\apache\bin\httpd.exe`.
2. **Adresses** :
   - Interface web du personnel : <http://localhost:8085/vsh_niger/vsh_serveur/public/app/>
   - Portail patient : <http://localhost:8085/vsh_niger/vsh_serveur/public/app/portail.html>
   - Vérification de l'API : <http://localhost:8085/vsh_niger/vsh_serveur/public/api/v1/health> doit répondre `200`.
3. **Application mobile** :
   - Démarrer l'émulateur Android.
   - Dans le dossier `vsh_mobile`, lancer `flutter run`, ou *Run ▸ Start Debugging* dans VS Code.
   - En débogage, l'application se connecte **automatiquement** au serveur XAMPP du poste : `http://10.0.2.2:8085/…`, où `10.0.2.2` désigne l'ordinateur vu depuis l'émulateur. Aucun paramètre n'est nécessaire.
   - Sur un **vrai téléphone** connecté au même Wi-Fi, ajouter l'adresse IP du poste, par exemple `flutter run --dart-define=VSH_DEV_HOST=192.168.1.20`.
4. **Position GPS de l'émulateur** : ouvrir *⋯ Extended controls ▸ Location* et saisir un point à Niamey, par exemple `13.5116, 2.1254`, puis *Set location*.
5. **Données déjà prêtes** dans la base de démonstration :
   - équipe « Équipe mobile Test », dont Aïcha est membre ;
   - soin « Pansement simple (TEST) » facturé 2 500 FCFA ;
   - acte « Consultation générale » facturé 5 000 FCFA ;
   - examen « Bilan TEST » ;
   - modèle d'ordonnance approuvé « Paludisme simple adulte ».

   Si le patient a déjà une visite ouverte, l'annuler d'abord : une seule visite ouverte est permise par patient.

## 3. Parcours de bout en bout

### Étape 1. Administrateur : vérifier le paramétrage (web, 3 minutes)

1. Se connecter à l'interface web avec `+22790000001`.
2. *Administration ▸ Paramètres* :
   - `homecare.auto_accept_new_requests = oui` : les demandes entrent directement dans la file ;
   - `homecare.dispatch_mode = BOTH` : auto-attribution et régulation ;
   - rayon d'arrivée `geo.arrival_radius_m = 300`.
3. *Administration ▸ Référentiels ▸ Types de soins* : « Pansement simple (TEST) » et son tarif.
4. *Administration ▸ Équipes* : « Équipe mobile Test », avec Aïcha comme membre actuelle.

> Message clé : aucun prix, droit ni utilisateur n'est écrit dans le code. Tout se paramètre et tout est versionné.

### Étape 2. Patient : demander une visite à domicile (portail, 5 minutes)

1. Ouvrir le portail patient. Saisir le dossier `VSH-2026-000002` et le téléphone `+22796445566`, puis *Recevoir un code*.
2. Recopier le code trouvé dans le journal du jour (voir §1).
3. Menu *Visites* ▸ *Demander une visite* :
   - motif : « Pansement au pied » ;
   - urgence : normale ;
   - repère : « Derrière la mosquée, portail bleu » ;
   - *Ma position actuelle*, ou garder la position du dossier.
4. La demande apparaît « En attente », avec un rappel : en cas d'urgence vitale, aller aux urgences.

> Message clé : connexion sans mot de passe (code SMS). Le patient ne voit que ses propres dossiers.

### Étape 3. Accueil ou régulation : suivre la demande (web, 3 minutes)

1. Se connecter avec `+22790000005` (Mariama, accueil).
2. *Carte* : la demande apparaît. La carte montre le numéro de dossier et le statut, **ni le nom ni le motif**.
3. *Visites à domicile* : ouvrir la demande. Deux choix :
   - **Affecter** : *Affecter une équipe* ▸ « Équipe mobile Test », avec les équipes classées par distance ;
   - **ou laisser l'infirmière prendre en charge** depuis son téléphone (étape 4).

### Étape 4. Infirmière : tournée **hors ligne** sur le mobile (émulateur, 12 minutes)

1. Dans l'application, se connecter avec `+22790000004`. La pastille passe au **vert, « Synchronisé »**.
2. Onglet **Tournée** : la demande est dans la « File d'attente ». Ouvrir la fiche :
   - motif et repère ;
   - bouton *Appeler* ;
   - carte OpenStreetMap avec le domicile ;
   - *Itinéraire*.
3. *Prendre en charge*.
4. **Couper le réseau** : mode avion de l'émulateur, ou *Extended controls ▸ Cellular ▸ Data status : Denied* et Wi-Fi coupé. La pastille passe au **rouge, « Hors connexion »**, et un bandeau rassure l'utilisateur.
5. Enchaîner **sans réseau**. Chaque étape s'affiche tout de suite, marquée « pas encore envoyé » :
   - *Partir vers le domicile* : la position GPS est relevée ;
   - *Je suis arrivé* : position relevée, avec avertissement si l'on est à plus de 300 m du domicile ;
   - *Commencer les soins* : la consultation à domicile s'ouvre sur le téléphone ;
   - *Constantes* : température `37,8`, pouls `88`, SpO₂ `97`. La virgule décimale est acceptée ;
   - *Soin réalisé* ▸ « Pansement simple (TEST) » ;
   - *Terminer la visite*.
6. Toucher la pastille pour ouvrir le **centre de synchronisation** : il liste les éléments « En attente d'envoi ».
7. **Rétablir le réseau**. La pastille passe à l'**orange, « Synchronisation en cours »**, puis au **vert**. Tout est envoyé dans l'ordre, une seule fois, et l'historique affiche l'état confirmé par la clinique.
8. Autres onglets :
   - **Soins** : soins à faire du jour ;
   - **Agenda** : rendez-vous du jour ;
   - **Patients** : recherche hors ligne, *Chercher aussi sur le serveur* et *Garder sur le téléphone* (épinglage).

> Messages clés :
> - zéro perte de données en cas de coupure ;
> - aucun doublon, même si l'envoi est répété ;
> - l'heure des actes est l'heure réelle, corrigée de l'horloge du téléphone ;
> - le GPS ne bloque jamais un soin ;
> - la base du téléphone est chiffrée.

### Étape 5. Régulation : contrôler le passage (web, 3 minutes)

1. En tant que Mariama : *Visites à domicile* ▸ la visite est **Terminée**.
2. La fiche montre :
   - la frise des étapes, avec l'heure réelle et l'heure de réception ;
   - les positions de départ et d'arrivée, avec la distance au domicile ;
   - le trajet ;
   - la consultation à domicile liée.

### Étape 6. Médecin : consultation, ordonnance, examen (web, 8 minutes)

1. Se connecter avec `+22790000002`.
2. Ouvrir la consultation de la visite (fiche de la visite ▸ *Consultation*, ou *Consultations*). Les constantes saisies par l'infirmière y figurent.
3. Compléter l'observation et ajouter un diagnostic (CIM-10).
4. *Ordonnances* ▸ modèle approuvé « Paludisme simple adulte », ou rédaction libre. Les alertes d'allergie s'affichent. *Signer*, puis télécharger le **PDF**.
5. *Examens* ▸ prescrire « Bilan TEST ».
6. *Clôturer* la consultation. La clôture est une décision médicale, distincte de la fin de la visite.

### Étape 7. Technicien puis médecin : résultat d'examen (web, 5 minutes)

1. Se connecter avec `+22790000003` : *Examens* ▸ file du technicien ▸ *Démarrer*, saisir les résultats structurés, *Terminer*.
2. Se reconnecter en médecin (`+22790000002`) : *Valider* le résultat (règle D-005). Le patient ne voit **que les résultats validés**.

### Étape 8. Accueil : facturation (web, 4 minutes)

1. En tant que Mariama : fiche de la visite **Terminée** ▸ *Facturer*. Le brouillon reprend le soin (2 500 FCFA) et les actes aux **tarifs en vigueur**.
2. *Émettre* la facture : elle reçoit un numéro définitif. Télécharger le **PDF**, avec l'en-tête de la clinique.
3. *Déclarer un règlement* (espèces ou mobile money). Le paiement se fait hors plateforme : l'enregistrement est seulement déclaratif (D-004).

### Étape 9. Patient : suivi et documents (portail, 3 minutes)

1. De retour sur le portail :
   - *Accueil* : la visite est terminée ;
   - *Résultats* : l'examen validé, sans interprétation (« parlez-en à votre médecin ») ;
   - *Documents* : l'ordonnance signée et la facture émise, en **PDF**.

### Étape 10. Administrateur : traçabilité (web, 2 minutes)

1. `+22790000001` ▸ *Administration ▸ Journal d'audit* : connexions, consultation de dossiers médicaux, signature, émission de facture, exports PDF, relevé GPS du domicile.
2. *Supervision de la synchronisation* : l'appareil de l'infirmière, ses opérations reçues, les conflits. Un appareil perdu peut être **révoqué** : ses données locales sont alors effacées à la connexion suivante.

## 4. Scénarios bonus (si le temps le permet)

| Scénario | Comment | Résultat attendu |
|---|---|---|
| Conflit de données | Couper le réseau du mobile. Modifier le téléphone d'un patient sur le mobile **et** sur le web avec une autre valeur. Rétablir le réseau | La valeur de la clinique est conservée. Le conflit apparaît dans le centre de synchronisation, bouton « Compris » |
| Visite prise par une autre équipe | Mobile hors ligne : *Prendre en charge*. En même temps, sur le web, l'affecter à une autre équipe. Rétablir le réseau | Refus visible sur le mobile. *Abandonner* retire aussi les étapes suivantes et affiche l'état réel |
| Application fermée pendant l'envoi | Fermer l'application pendant la synchronisation, puis la rouvrir | L'envoi reprend, sans doublon |
| Nouveau patient sur le terrain | Mobile hors ligne : *Patients* ▸ *Nouveau patient* | Le numéro de dossier est attribué par le serveur à la synchronisation. Un doublon possible est signalé à l'accueil |

## 5. En cas de problème

| Symptôme | Cause probable | Solution |
|---|---|---|
| Mobile : « Application non configurée » | Version publiée (*release*) sans adresse du serveur | En démonstration, lancer en débogage (`flutter run`). En production : `--dart-define=VSH_API_BASE_URL=https://…/api/v1` |
| Mobile : pastille rouge alors que le poste est en ligne | Apache arrêté, ou vrai téléphone qui ne peut pas joindre `10.0.2.2` | Ouvrir l'URL de vérification (§2). Sur téléphone : `--dart-define=VSH_DEV_HOST=<IP du poste>` et port 8085 autorisé dans le pare-feu Windows |
| Portail : « code incorrect ou expiré » | Code d'une autre demande, ou plus de 5 minutes écoulées | Redemander un code après 60 s, puis lire la **dernière** ligne du journal |
| Portail : demande de visite refusée | Une visite est déjà ouverte pour ce patient | L'annuler, ou la terminer |
| « Soin réalisé » : liste vide | Référentiels pas encore reçus sur le téléphone | Synchroniser une fois en ligne (tirer la liste vers le bas) |
