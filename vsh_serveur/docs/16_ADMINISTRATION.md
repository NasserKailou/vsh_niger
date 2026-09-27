# Administration (étape 9e)

Les écrans d'administration de l'interface web s'appuient sur l'API existante. Les droits sont revérifiés par le serveur à chaque requête : masquer un écran ou un bouton n'est qu'un confort d'affichage.

## 1. Écrans

| Écran | Droits | Contenu |
|---|---|---|
| Utilisateurs et rôles (`#/admin/users`) | `users.read`, `users.manage`, `roles.manage` | **Personnel** : recherche (nom, téléphone), filtres par statut et rôle ; création d'un compte (profession, spécialité, n° d'inscription imprimé sur les ordonnances, rôles) ; modification, suspension, réactivation, réinitialisation du mot de passe. **Rôles** : permissions groupées par module, création d'un rôle, modification, suppression d'un rôle non système et sans compte. |
| Équipes (`#/admin/teams`) | `teams.manage` | Équipes mobiles ou de service, actives ou non ; membres avec leur rôle (chef, médecin, infirmier…) et une appartenance **datée**. Retirer un membre fixe une date de fin : rien n'est effacé, l'historique des visites reste attribué. |
| Référentiels et tarifs (`#/admin/reference`) | `reference.manage`, `tariffs.manage`, `prescription_templates.*` | Services et **plages horaires** ; actes médicaux ; types de soins ; types d'examens et **paramètres de résultats** (type, unité, valeurs de référence, sexe, âge) ; médicaments. Un élément se **désactive**, il ne se supprime pas. **Tarifs** : historique par prestation, nouveau tarif daté (le précédent est clôturé la veille), fin d'un tarif en vigueur. **Modèles d'ordonnance** : rédaction, approbation, archivage. |
| Paramètres (`#/admin/settings`) | `settings.manage` | Réglages métier groupés par thème, avec un contrôle adapté au type : case à cocher, nombre, liste de choix, liste, en-tête des documents. Seules les valeurs modifiées sont envoyées ; les erreurs s'affichent sur le bon paramètre. |
| Journal d'audit (`#/admin/audit`) | `audit.read`, `sync.supervise` | **Journal** : filtres par action, type et identifiant d'objet, période ; détail de chaque entrée (valeurs avant et après, IP, appareil, requête) ; un clic sur un objet affiche tout son historique. **Synchronisation** : appareils (dernière synchronisation, rejets, conflits, révocation) et opérations rejetées, en conflit ou appliquées. |

## 2. Règles appliquées

- **Mot de passe temporaire** : il est affiché une seule fois, dans une fenêtre dédiée avec un bouton de copie, et doit être remis en personne. L'utilisateur le change à sa première connexion. L'interface ne le conserve pas.
- **Séparation des rôles** : l'administrateur peut rédiger un modèle d'ordonnance, mais seul un utilisateur ayant le droit `prescription_templates.approve` (médecin) peut l'approuver. Modifier un modèle approuvé crée une nouvelle version en brouillon, qu'il faut réapprouver.
- **Rien n'est préchargé** : référentiels, tarifs et modèles sont saisis par la clinique. Aucune donnée fictive n'est fournie.
- **Rien n'est mis hors d'usage sans trace** : désactivation plutôt que suppression, fin d'appartenance datée, tarifs versionnés. Chaque modification est inscrite au journal d'audit.
- **Pas de secret à l'écran** : les réglages techniques (base de données, clés, passerelle SMS) restent dans `.env` et n'apparaissent jamais dans les paramètres.
- **Saisie des décimales** : les valeurs de référence acceptent la virgule française (« 0,7 »).

## 3. API ajoutée : journal d'audit

`GET /audit-logs` (droit `audit.read`, **lecture seule**, résultats paginés, du plus récent au plus ancien) :

| Filtre | Format |
|---|---|
| `action` | Code en majuscules (ex. `SETTINGS_UPDATED`) |
| `entity_type` | Ex. `patient`, `invoice` |
| `entity_id` | UUID de l'objet |
| `user_id` | UUID de l'auteur |
| `from`, `to` | Jours **locaux** de l'établissement (`app.timezone`), convertis en bornes UTC |

Chaque entrée contient : `action`, `entity_type`, `entity_id`, `user` (nom, compte patient ou non), `device`, `ip_address`, `user_agent` (tronqué), `request_id`, `old_values`, `new_values`, `created_at`.

`GET /audit-logs/facets` renvoie les actions et les types d'objet présents dans le journal, pour alimenter les filtres. Aucune route ne permet de modifier ou de supprimer une entrée.

## 4. Validation renforcée des paramètres JSON

- `documents.letterhead` : seules les clés `address`, `phone`, `email`, `invoice_footer` et `prescription_footer` sont acceptées, en textes de 255 caractères au plus.
- `uploads.allowed_mime_types` : liste non vide de types MIME (ex. `application/pdf`).

## 5. Tests

`tests/Api/AuditLogTest.php` couvre :

- les filtres par objet, action et auteur ;
- l'ordre du plus récent au plus ancien ;
- la période en jours locaux ;
- les listes de valeurs des filtres ;
- le refus d'un filtre mal formé ;
- l'accès refusé sans `audit.read` ;
- l'absence de route d'écriture ;
- la validation des paramètres JSON.

## 6. Vérifications dans le navigateur

- Création d'un compte : refusée sans rôle, puis mot de passe temporaire affiché une seule fois.
- Rôles : permissions groupées et cochées.
- Acte médical ajouté (code mis en majuscules), puis tarif créé et marqué « en vigueur ».
- Paramètre d'examen avec des valeurs de référence décimales.
- Modèle d'ordonnance rédigé par l'administrateur (sans bouton d'approbation), puis approuvé par le médecin (qui ne voit que cet onglet).
- Équipe et membres affichés.
- Paramètres : deux valeurs modifiées, tracées dans le journal avec l'avant et l'après lisibles.
- Onglet de synchronisation affiché.
- Console sans erreur.
