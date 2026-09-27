# Installation

## Prérequis

| Composant | Version |
|---|---|
| PHP | ≥ 7.3 (testé en 7.3.33 et 8.2.12) avec `pdo_mysql`, `mbstring`, `openssl`, `fileinfo`, `json` |
| Base de données | MariaDB ≥ 10.4 ou MySQL ≥ 8.0.16 |
| Serveur web | Apache 2.4 avec `mod_rewrite` (et `mod_headers` recommandé), ou Nginx |
| Composer | Facultatif en production (aucune dépendance d'exécution) ; nécessaire pour lancer les tests |

## Poste de développement (XAMPP, Windows)

Dans cette installation, Apache écoute sur le port **8085** (le port 80 est occupé par Windows).

```bash
cd vsh_serveur
cp .env.example .env              # puis : APP_ENV=local, APP_DEBUG=true, DB_*…
php bin/console.php key:generate  # copier la valeur dans APP_KEY
mysql -u root -e "CREATE DATABASE vsh_homecare_dev CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
php bin/console.php migrate
php bin/console.php seed
composer install                  # outils de test
php vendor/bin/phpunit
```

Vérification : `http://localhost:8085/vsh_niger/vsh_serveur/public/api/v1/health` doit répondre `{"success":true,…,"status":"ok"}`.

> **`403 Forbidden` sur `http://localhost:8085/vsh_niger/vsh_serveur/` : c'est voulu.** Le dossier racine contient `.env`, la configuration, la base, les journaux et les fichiers déposés. Son `.htaccess` refuse tout accès. Seul `public/` est exposé. Toutes les routes de l'API commencent par `…/vsh_serveur/public/api/v1/`. En production, la racine du site (DocumentRoot / virtual host) doit pointer **directement** sur `vsh_serveur/public`. L'interface web arrivera à l'étape 9 : en attendant, `…/public/` seul répond `404`, car il n'y a pas encore de page d'accueil.

## Production

1. **Code** : déployer `vsh_serveur/` sans `vendor/`, `tests/` ni `.env` de développement (Composer n'est pas requis).
2. **Serveur web** : le `DocumentRoot` pointe sur `vsh_serveur/public`, **jamais** sur `vsh_serveur/`.

   ```apache
   <VirtualHost *:443>
       ServerName api.exemple.ne
       DocumentRoot /var/www/vsh_serveur/public
       <Directory /var/www/vsh_serveur/public>
           AllowOverride All
           Require all granted
       </Directory>
       SSLEngine on
       # certificats…
   </VirtualHost>
   ```

   Rediriger tout le HTTP vers HTTPS : les jetons et les données médicales ne doivent jamais circuler en clair.
3. **Configuration** : `.env` avec `APP_ENV=production`, `APP_DEBUG=false`, une `APP_KEY` propre à la production, un utilisateur MySQL dédié (droits limités à la base, pas `root`), `CORS_ALLOWED_ORIGINS` (origines des interfaces web) et `TRUSTED_PROXIES` si un reverse proxy est utilisé.
4. **Droits fichiers** : `storage/` en écriture pour l'utilisateur du serveur web uniquement ; `.env` lisible par lui seul (`chmod 600`).
5. **Base** : `php bin/console.php migrate` puis `php bin/console.php seed`.
6. **Contrôle** : `GET https://api.exemple.ne/api/v1/health` → 200 ; `https://api.exemple.ne/.env` → 404 ou 403.
7. **Tâches quotidiennes** (cron, ou planificateur de tâches Windows) :

   ```bash
   php bin/console.php security:purge   # jetons, codes OTP, tentatives expirés
   php bin/console.php geo:purge        # points de trajet des équipes au-delà de geo.trace_retention_days
   ```

   Et **chaque minute**, l'envoi des notifications push et SMS ([21_NOTIFICATIONS.md](21_NOTIFICATIONS.md)) :

   ```bash
   * * * * * cd /var/www/vsh_serveur && php bin/console.php notifications:dispatch >> storage/logs/dispatch.log 2>&1
   ```

   Push : `PUSH_DRIVER=fcm` et `FCM_CREDENTIALS_FILE` (compte de service Firebase, hors de `public/`) dans `.env`, puis activer `notifications.push_enabled` dans les paramètres. Sur le poste de développement, `php bin/console.php notifications:dispatch --watch` remplace le cron.

8. **HTTPS obligatoire aussi pour la géolocalisation** : les navigateurs ne fournissent la position de l'appareil (relevé GPS, partage pendant les visites) que sur une connexion sécurisée, ou sur `localhost` en développement.

Les sauvegardes, la supervision et la maintenance seront documentées à l'étape 14 (durcissement).
