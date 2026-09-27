# Montée en charge : analyse et plan d'action

Objectif : rester rapide avec **plusieurs millions de dossiers patients** et une application mobile utilisée à la fois par le personnel et par les patients (espace patient, [18_APPLICATION_MOBILE.md §11](18_APPLICATION_MOBILE.md)).

## 1. Ce qui change avec l'application patient

| | Personnel seul | Personnel + patients |
|---|---|---|
| Appareils connectés | quelques centaines | des centaines de milliers |
| Données par appareil | périmètre de soins (milliers de dossiers) | 1 à quelques dossiers (famille) |
| Requêtes | saisie, synchronisation | consultation, rafraîchissements, pics (résultats publiés, ouverture des créneaux) |
| Coût variable | faible | SMS (codes de connexion, notifications) |

Les **téléphones des patients** fixent le dimensionnement. Chaque requête patient doit donc coûter un temps **indépendant de la taille de la base**.

## 2. Mesures

Base `vsh_bench`, séparée de la base de développement :

- 1 000 000 de patients ;
- 1 000 000 de consultations ;
- 2 000 000 de relevés de constantes ;
- 3 000 000 de lignes dans le journal de synchronisation, soit environ 2 Go.

Conditions : MariaDB 10.4 de XAMPP, avec une mémoire tampon (`innodb_buffer_pool_size`) portée temporairement de **16 Mo** (valeur par défaut de XAMPP) à 2 Go, pour être représentatif d'un serveur. Avec 16 Mo, les mêmes requêtes dépassaient 60 s.

| Opération | Temps mesuré | Verdict |
|---|---|---|
| Recherche par n° de dossier, par téléphone | 1 à 8 ms | ✔ |
| Détection des doublons à la création | 2 ms | ✔ |
| Recherche par nom « Mo » (actuelle : `prénom LIKE … OR nom LIKE …`) | 2 400 à 3 000 ms par page, plus 680 ms de `COUNT(*)` | ✖ |
| Même recherche, proposée (deux branches indexées, 51 lignes, sans comptage exact) | **3 ms** ; 176 ms dans le pire cas réaliste | ✔ |
| Tableau de bord : « patients actifs » (`COUNT(*)` sur 980 000 lignes) | 3 230 ms à chaque affichage | ✖ |
| Tableau de bord : consultations et nouveaux patients du mois | 380 à 770 ms | ⚠ |
| Pull d'un médecin (vraie requête, page de 200) | 290 à 430 ms | ⚠ |
| Pull d'un **patient**, nouvel appareil | **3 430 ms pour 3 lignes** : tout le journal est parcouru | ✖ |
| Périmètre calculé une fois (table temporaire), puis pull | 3,6 ms appareil à jour, 190 ms nouvel appareil | ✔ |

## 3. Constats

### Critique

1. **Pull de synchronisation des patients.** Le journal global est parcouru pour trouver les quelques changements d'un patient. Le coût grandit avec le journal : environ 3,4 s à 3 M de lignes, et près d'une minute à 50 M, pour chaque téléphone.
   → **Fait** : les patients ne font plus de pull. L'espace patient lit `/me/patients/…`, dont le coût est borné par ses propres dossiers.
2. **Périmètre de synchronisation du personnel.**
   - Les 12 sous-requêtes « contributions » (`created_by = ? AND created_at >= ?`) n'ont aucun index : chacune parcourt entièrement sa table.
   - Le périmètre est recalculé à chaque page de pull.
3. **Recherche par nom.** Le `OR` entre prénom et nom empêche l'usage des index, et un `COUNT(*)` exact est fait à chaque recherche.

### Important

4. **Tableau de bord** : une quinzaine de `COUNT(*)` recalculés à chaque affichage, certains sans index adapté (`patients.created_at`, `consultations.started_at`).
5. **Pagination par `OFFSET` et comptage exact** partout : le coût augmente avec le numéro de page.
6. **Croissance sans limite** de `sync_changes`, `audit_logs` (chaque lecture de dossier médical est tracée, D-010), `notifications` et `notification_deliveries`.
7. **Pull du personnel** : l'état de chaque changement est relu un par un (200 requêtes par page).

### Environnement de production

8. **opcache désactivé** dans le PHP de XAMPP : PHP recompile chaque fichier à chaque requête.
9. **Mémoire MySQL** : `innodb_buffer_pool_size` doit contenir les index actifs (70 % de la RAM d'un serveur dédié à la base).

### Mobile

10. **Personnel** : les éléments locaux (visites terminées, notifications) ne sont jamais purgés, et chaque changement relit et décode tout l'historique d'une entité.
11. **Patients** : volume local borné. Seules les 50 dernières notifications sont conservées, et les PDF sont effacés à la déconnexion.

## 4. Plan d'action

| Priorité | Action | Effet attendu |
|---|---|---|
| ✔ fait | Espace patient mobile sans pull de journal (`/me/patients/…`, cache local chiffré) | Coût par patient indépendant de la base |
| ✔ fait | Connexion patient liée à l'appareil : 30 jours sans nouveau SMS, push, révocation | Moins de SMS, notifications gratuites |
| ✔ fait | Notifications par push, SMS seulement en repli (D-011) | Coût SMS maîtrisé |
| ✔ fait | Migration 0018 : index `(created_by, created_at)` sur les 12 tables de contributions, `patients(first_name, last_name)`, `patients(created_at)`, `consultations(started_at)` | Périmètre complet d'un médecin (29 000 dossiers) : **118 ms**, contre 2,4 s |
| ✔ fait | Recherche par nom en deux branches indexées (`UNION`), total plafonné à 1 000 (« plus de 1 000 résultats, précisez ») | Mesuré sur 1 M de dossiers : « Mo » 2 400 ms → **14 ms** ; « Moussa » → 10 ms ; prénom très courant : 274 ms au pire |
| ✔ fait | Recherche au fil de la frappe : web (400 ms) et mobile (serveur interrogé dès 3 lettres) | Plus de bouton « Rechercher » à l'accueil |
| P1 | Périmètre du personnel calculé une fois par synchronisation (table temporaire, ou table `sync_scope` tenue à jour) | Pull d'un appareil à jour : 300 ms → 4 ms |
| P1 | opcache activé et `innodb_buffer_pool_size` dimensionné en production (04_INSTALLATION) | ×3 à ×10 sur toutes les requêtes |
| P2 | Compteurs du tableau de bord en table de synthèse ou en cache de 60 s | 3 s → quelques ms |
| P2 | Pagination par curseur (`WHERE (nom, id) > (?, ?)`) sur les longues listes | Coût constant quelle que soit la page |
| P2 | Lecture groupée de l'état des changements au pull (une requête par entité) | 200 → ~10 requêtes par page |
| P2 | Rétention et partitionnement par mois : `sync_changes` (au-delà d'un an, un appareil trop ancien refait une synchronisation initiale), `audit_logs` archivé, notifications lues de plus de 6 mois | Tables de taille stable |
| P3 | Réplica en lecture pour le tableau de bord et les rapports ; limitation de débit par compte sur les routes patient | Pics absorbés sans ralentir la saisie |
| P3 | Mobile personnel : purge des visites terminées et des dossiers sortis du périmètre | Application fluide après des mois d'usage |

## 5. Expérience utilisateur

Ce qui est fait dans l'espace patient :

- **Affichage immédiat** depuis la base locale, puis actualisation (tirer vers le bas) ; consultation possible hors ligne.
- **Actions en 2 touchers** depuis l'accueil : « Prendre rendez-vous » et « Demander une visite à domicile ».
- **Prise de rendez-vous** : créneaux libres affichés, pas de saisie d'heure libre.
- **Visite à domicile** : suivi par étapes, de « Demande reçue » à « Visite terminée ». Adresse et repère sont préremplis, avec la position GPS en un toucher.
- **Documents** : résultats lisibles avec la mention « hors valeurs de référence » ; ordonnances et factures en PDF.
- **Famille** (D-009) : on passe d'un dossier à l'autre par des pastilles en haut de l'écran.

À prévoir :

- recherche du personnel « au fil de la frappe » une fois la recherche indexée (P1) ;
- squelettes de chargement au lieu d'indicateurs ;
- inscription d'un nouveau patient directement depuis l'application (`/auth/register`, route déjà prête).

## 6. Reproduire les mesures

Les scripts (génération des données et mesures) se trouvent dans le dossier temporaire de la session. La démarche :

1. Créer la base `vsh_bench` : `DB_DATABASE=vsh_bench php bin/console.php migrate` puis `seed`.
2. Générer les données avec le moteur `SEQUENCE` de MariaDB (`seq_1_to_1000000`), en 20 minutes environ.
3. Mesurer chaque requête avec `SET STATEMENT max_statement_time=60 FOR …`, après avoir porté `innodb_buffer_pool_size` à 2 Go.

`vsh_bench` n'est pas utilisée par l'application ; elle peut être supprimée (`DROP DATABASE vsh_bench`) pour libérer environ 2 Go.
