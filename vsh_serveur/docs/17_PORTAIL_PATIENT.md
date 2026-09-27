# Portail web patient (étape 9f)

Adresse : `…/public/app/portail.html`. Un lien y mène depuis l'écran de connexion du personnel, et inversement.

## 1. Connexion (D-001)

Le patient se connecte **sans mot de passe** :

1. Il saisit son **numéro de dossier** et le **téléphone enregistré** au dossier.
2. Le serveur répond **toujours de la même façon**, que les informations soient exactes ou non (pas d'énumération des dossiers). Les demandes sont limitées en fréquence.
3. Le patient saisit le **code reçu par SMS** (usage unique, valable quelques minutes, renvoi possible après 60 s).
4. À la première connexion, un compte patient sans mot de passe est créé et rattaché au téléphone vérifié.

Les erreurs restent volontairement génériques (« code incorrect ou expiré, ou informations inexactes »). Deux cas ont un message dédié : compte désactivé, numéro appartenant à un membre du personnel.

La session du portail est **distincte** de celle du personnel : clé de stockage `vsh.portal.rt`, dans l'onglet seulement. Un compte du personnel est refusé sur le portail, et un compte patient est refusé dans l'espace de la clinique.

En développement (`SMS_DRIVER=log`), le SMS est écrit dans `storage/logs/app-AAAA-MM-JJ.log`. En production, configurer une vraie passerelle SMS : `SMS_DRIVER` dans `.env`, et `config/sms.php`.

## 2. Écrans (mobile d'abord)

La navigation se trouve en bas de l'écran sur téléphone et en haut sur ordinateur. Un sélecteur permet de changer de dossier quand le compte gère ceux de sa famille.

| Écran | Contenu |
|---|---|
| Accueil | Carte du dossier ; raccourcis (rendez-vous, visite, documents) ; prochain rendez-vous ; visite en cours avec son avancement ; messages de la clinique (notifications, « tout marquer comme lu »). |
| Rendez-vous | Rendez-vous à venir et historique. **Demande** : service, date, créneaux libres, motif ; la clinique confirme ensuite. **Annulation** dans le délai fixé par la clinique (`appointments.patient_cancel_min_hours`). |
| Visites | Rappel « urgence vitale : allez aux urgences ». **Demande** : motif, urgence, téléphone, adresse, repère et **position GPS** (préremplis depuis le dossier ; « Ma position actuelle »). Avancement en langage simple. **Annulation** tant que l'équipe n'est pas arrivée. |
| Résultats | Examens **validés** seulement, avec la valeur, l'unité et la référence. Mention « hors valeurs de référence », sans interprétation : « parlez-en à votre médecin ». |
| Documents | Ordonnances **signées** et factures **émises**, avec leur détail et le **PDF** ; état de règlement déclaré par la clinique (D-004). |

## 3. Règles

- Toutes les données passent par les routes `/me/patients/{id}/…`. Le serveur vérifie à chaque appel que le dossier est rattaché au compte.
- Un dossier **en attente de validation** peut demander un rendez-vous (il sera validé à l'accueil), mais pas une visite à domicile.
- Aucune donnée médicale n'apparaît dans les notifications ni dans les URL. Les PDF sont téléchargés avec le jeton dans l'en-tête, jamais dans l'URL.
- Même politique de sécurité (CSP) que l'interface du personnel.

## 4. Vérifications (navigateur, 390 px)

- Connexion : demande de code limitée en fréquence (réponse 429), mauvais code refusé avec un message générique, bon code accepté.
- Accueil affiché ; ordonnances et factures listées, PDF téléchargés.
- Rendez-vous demandé sur un créneau libre, puis annulé.
- Visite demandée avec la position GPS du dossier (±6 m), avancement affiché, puis annulée.
- Déconnexion : jeton retiré du stockage de l'onglet.
- Console sans erreur (seul un script injecté par un antivirus est bloqué par la CSP).
