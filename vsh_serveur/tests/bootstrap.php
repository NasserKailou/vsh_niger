<?php

declare(strict_types=1);

use Vsh\Core\Config;
use Vsh\Core\Migrations\Migrator;

require dirname(__DIR__) . '/vendor/autoload.php';

if (!defined('VSH_BASE_PATH')) {
    define('VSH_BASE_PATH', dirname(__DIR__));
}

// Base de test reconstruite à chaque exécution. Garde-fou : le nom doit se terminer par « _test ».
(function (): void {
    $container = require VSH_BASE_PATH . '/bootstrap/app.php';
    restore_error_handler();
    $database = (array) $container->get(Config::class)->get('database');
    if (substr((string) $database['database'], -5) !== '_test') {
        fwrite(STDERR, 'Refusé : la base de test doit se terminer par « _test » (DB_DATABASE dans phpunit.xml).' . PHP_EOL);
        exit(1);
    }
    $server = new PDO(
        sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $database['host'], (int) $database['port']),
        (string) $database['username'],
        (string) $database['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    $server->exec('CREATE DATABASE IF NOT EXISTS `' . $database['database'] . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

    $migrator = $container->get(Migrator::class);
    $silent = function (string $line): void {
    };
    $migrator->dropAllTables();
    $migrator->migrate($silent);
    $migrator->seed($silent);
})();
