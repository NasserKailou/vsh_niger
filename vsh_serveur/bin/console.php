<?php

declare(strict_types=1);

use Vsh\Core\Config;
use Vsh\Core\Database;
use Vsh\Core\Migrations\Migrator;
use Vsh\Core\Security\AuditLogger;
use Vsh\Core\Security\PasswordPolicy;
use Vsh\Core\Support\Clock;
use Vsh\Core\Support\Uuid;
use Vsh\Core\Validation\Validator;
use Vsh\Modules\Users\RoleRepository;
use Vsh\Modules\Users\UserRepository;

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$container = require dirname(__DIR__) . '/bootstrap/app.php';
/** @var Config $config */
$config = $container->get(Config::class);

$arguments = array_slice($argv, 1);
$command = $arguments[0] ?? 'help';
$options = array_slice($arguments, 1);

$out = function (string $line): void {
    fwrite(STDOUT, $line . PHP_EOL);
};
$fail = function (string $line): void {
    fwrite(STDERR, $line . PHP_EOL);
    exit(1);
};

$usage = <<<TXT
Vision Homecare — console

Utilisation : php bin/console.php <commande> [options]

  migrate                  Applique les migrations en attente
  migrate:status           Liste les migrations et leur état
  migrate:fresh --force    [local/testing uniquement] Supprime toutes les tables puis rejoue les migrations
                           (ajouter --seed pour rejouer aussi les seeds)
  seed                     Rejoue les seeds (idempotents)
  key:generate             Affiche une nouvelle clé APP_KEY à copier dans .env
  create-admin             Crée un compte administrateur (mot de passe temporaire affiché une seule fois)
                           Options : --first-name= --last-name= --phone= [--email=]
  security:purge           Supprime les jetons, codes OTP et tentatives de connexion expirés
  geo:purge                Supprime les points de trajet des équipes plus anciens que geo.trace_retention_days
                           (les positions de départ et d'arrivée, preuves de passage, sont conservées)
  notifications:dispatch   Envoie les notifications push et SMS en attente (cron chaque minute)
                           Option : --watch[=secondes] tourne en continu (poste de développement, sans cron)
  help                     Affiche cette aide
TXT;

/**
 * Valeur d'une option --nom=valeur, ou saisie interactive si absente.
 */
$option = function (string $name, string $prompt, bool $required = true) use ($options): ?string {
    foreach ($options as $argument) {
        if (strpos($argument, '--' . $name . '=') === 0) {
            return substr($argument, strlen($name) + 3);
        }
    }
    fwrite(STDOUT, $prompt . ($required ? ' : ' : ' (facultatif) : '));
    $line = fgets(STDIN);
    $value = $line === false ? '' : trim($line);
    return $value === '' ? null : $value;
};

try {
    switch ($command) {
        case 'migrate':
            $count = $container->get(Migrator::class)->migrate($out);
            $out($count === 0 ? 'Aucune migration en attente.' : $count . ' migration(s) appliquée(s).');
            break;

        case 'migrate:status':
            foreach ($container->get(Migrator::class)->status() as $row) {
                $state = $row['applied'] ? 'appliquée le ' . $row['applied_at'] . ' UTC (lot ' . $row['batch'] . ')' : 'EN ATTENTE';
                if ($row['modified']) {
                    $state .= '  ⚠ MODIFIÉE depuis son application';
                }
                $out(sprintf('%-40s %s', $row['file'], $state));
            }
            break;

        case 'migrate:fresh':
            $environment = (string) $config->get('app.env');
            if (!in_array($environment, ['local', 'testing'], true)) {
                $fail('Refusé : migrate:fresh n\'est autorisé qu\'avec APP_ENV=local ou APP_ENV=testing.');
            }
            $database = $container->get(Database::class)->databaseName();
            if (!in_array('--force', $options, true)) {
                $fail('Cette commande SUPPRIME toutes les tables de la base « ' . $database . ' ». Relancez avec --force pour confirmer.');
            }
            $migrator = $container->get(Migrator::class);
            $migrator->dropAllTables();
            $out('Tables supprimées dans « ' . $database . ' ».');
            $out($migrator->migrate($out) . ' migration(s) appliquée(s).');
            if (in_array('--seed', $options, true)) {
                $out($migrator->seed($out) . ' seed(s) exécuté(s).');
            }
            break;

        case 'seed':
            $out($container->get(Migrator::class)->seed($out) . ' seed(s) exécuté(s).');
            break;

        case 'key:generate':
            $out(bin2hex(random_bytes(32)));
            $out('Copiez cette valeur dans APP_KEY (fichier .env). Ne la partagez pas et ne la changez pas en production :');
            $out('les codes OTP en cours deviendraient invalides.');
            break;

        case 'create-admin':
            $validator = $container->get(Validator::class);
            $data = $validator->validate([
                'first_name' => $option('first-name', 'Prénom'),
                'last_name' => $option('last-name', 'Nom'),
                'phone' => $option('phone', 'Téléphone (ex. 90 12 34 56)'),
                'email' => $option('email', 'E-mail', false),
            ], [
                'first_name' => 'required|string|max:100',
                'last_name' => 'required|string|max:100',
                'phone' => 'required|phone',
                'email' => 'nullable|email|max:190',
            ]);
            $users = $container->get(UserRepository::class);
            if ($users->phoneTaken($data['phone'])) {
                $fail('Ce numéro de téléphone est déjà utilisé.');
            }
            $roleIds = $container->get(RoleRepository::class)->roleIds(['ADMIN']);
            if (!isset($roleIds['ADMIN'])) {
                $fail('Rôle ADMIN introuvable : exécutez d\'abord « php bin/console.php seed ».');
            }
            $temporaryPassword = PasswordPolicy::generateTemporary();
            $userUuid = Uuid::v4();
            $container->get(Database::class)->transaction(function () use ($container, $users, $data, $roleIds, $temporaryPassword, $userUuid): void {
                $id = $users->create([
                    'uuid' => $userUuid,
                    'account_type' => 'STAFF',
                    'phone' => $data['phone'],
                    'email' => $data['email'] ?? null,
                    'password_hash' => PasswordPolicy::hash($temporaryPassword),
                    'first_name' => $data['first_name'],
                    'last_name' => $data['last_name'],
                    'status' => 'ACTIVE',
                    'must_change_password' => true,
                ]);
                $users->replaceRoles($id, [$roleIds['ADMIN']], null);
                $users->saveStaffProfile($id, ['profession' => 'ADMINISTRATIF']);
                $container->get(AuditLogger::class)->record('ADMIN_CREATED_CLI', null, 'user', $userUuid, null, [
                    'phone' => $data['phone'],
                    'roles' => ['ADMIN'],
                ], $id);
            });
            $out('Administrateur créé : ' . $data['first_name'] . ' ' . $data['last_name'] . ' (' . $data['phone'] . ')');
            $out('Mot de passe temporaire (affiché une seule fois, à changer à la première connexion) : ' . $temporaryPassword);
            break;

        case 'security:purge':
            $db = $container->get(Database::class);
            $now = Clock::nowForDatabase();
            $monthAgo = Clock::now()->modify('-30 days')->format('Y-m-d H:i:s');
            $dayAgo = Clock::now()->modify('-1 day')->format('Y-m-d H:i:s');
            $counts = [
                'jetons d\'accès' => $db->execute('DELETE FROM access_tokens WHERE expires_at < ? OR (revoked_at IS NOT NULL AND revoked_at < ?)', [$dayAgo, $dayAgo]),
                'refresh tokens' => $db->execute('DELETE FROM refresh_tokens WHERE expires_at < ? OR (revoked_at IS NOT NULL AND revoked_at < ?)', [$now, $monthAgo]),
                'codes OTP' => $db->execute('DELETE FROM otp_codes WHERE expires_at < ?', [$dayAgo]),
                'tentatives de connexion' => $db->execute('DELETE FROM login_attempts WHERE attempted_at < ?', [$monthAgo]),
            ];
            foreach ($counts as $label => $count) {
                $out(sprintf('%-25s %d supprimé(s)', $label, $count));
            }
            break;

        case 'geo:purge':
            $count = $container->get(\Vsh\Modules\Homecare\HomecareService::class)->purgeTraces();
            $out(sprintf('%d point(s) de trajet supprimé(s).', $count));
            break;

        case 'notifications:dispatch':
            $interval = null;
            foreach ($options as $argument) {
                if ($argument === '--watch' || strpos($argument, '--watch=') === 0) {
                    $interval = max(2, (int) (substr($argument, 8) ?: 10));
                }
            }
            $dispatcher = $container->get(\Vsh\Modules\Notifications\NotificationDispatcher::class);
            do {
                $container->get(\Vsh\Modules\Settings\SettingsService::class)->refresh();
                $counts = $dispatcher->dispatch();
                if ($counts === null) {
                    $out('Un autre envoi est en cours : rien à faire.');
                } elseif ($interval === null || array_sum($counts) > 0) {
                    $out(sprintf(
                        '%s envoyé(s) %d, ignoré(s) %d, à retenter %d, en échec %d',
                        gmdate('H:i:s'),
                        $counts['sent'],
                        $counts['skipped'],
                        $counts['retry'],
                        $counts['failed']
                    ));
                }
                if ($interval !== null) {
                    sleep($interval);
                }
            } while ($interval !== null);
            break;

        case 'help':
            $out($usage);
            break;

        default:
            $out($usage);
            $fail('Commande inconnue : ' . $command);
    }
} catch (\Throwable $exception) {
    $fail('Erreur : ' . $exception->getMessage());
}
