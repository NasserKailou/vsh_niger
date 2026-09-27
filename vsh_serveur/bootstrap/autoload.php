<?php

declare(strict_types=1);

// Chargement automatique PSR-4 sans dépendance : l'application fonctionne même sans `composer install`
// (hébergements sans Composer). Composer, s'il est installé, ajoute les outils de développement.
$composerAutoload = dirname(__DIR__) . '/vendor/autoload.php';
if (is_file($composerAutoload)) {
    require_once $composerAutoload;
}

spl_autoload_register(function (string $class): void {
    $prefix = 'Vsh\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $file = dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require $file;
    }
});
