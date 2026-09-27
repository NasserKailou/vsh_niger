<?php

declare(strict_types=1);

use Vsh\Core\Config;
use Vsh\Core\Container;
use Vsh\Core\Database;
use Vsh\Core\Env;
use Vsh\Core\ErrorHandler;
use Vsh\Core\Http\Kernel;
use Vsh\Core\Logger;
use Vsh\Core\Migrations\Migrator;
use Vsh\Core\Routing\Router;
use Vsh\Core\Push\FcmPushGateway;
use Vsh\Core\Push\LogPushGateway;
use Vsh\Core\Push\NullPushGateway;
use Vsh\Core\Push\PushGatewayInterface;
use Vsh\Core\Sms\HttpSmsGateway;
use Vsh\Core\Sms\LogSmsGateway;
use Vsh\Core\Sms\SmsGatewayInterface;
use Vsh\Core\Sms\TwilioSmsGateway;
use Vsh\Core\Support\HttpTransport;
use Vsh\Core\Support\Phone;
use Vsh\Core\Validation\Validator;
use Vsh\Modules\Sync\SyncRegistry;

if (!defined('VSH_BASE_PATH')) {
    define('VSH_BASE_PATH', dirname(__DIR__));
}

require_once __DIR__ . '/autoload.php';

Env::load(VSH_BASE_PATH . '/.env');
date_default_timezone_set('UTC');
mb_internal_encoding('UTF-8');

$config = Config::fromDirectory(VSH_BASE_PATH . '/config');
Phone::setDefaultCountryCode((string) $config->get('security.default_country_code', '227'));

$container = new Container();
$container->instance(Container::class, $container);
$container->instance(Config::class, $config);

$container->set(Logger::class, function () use ($config) {
    return new Logger((string) $config->get('logging.path'), (string) $config->get('logging.level', 'info'));
});

$container->set(Database::class, function () use ($config) {
    return new Database((array) $config->get('database'));
});

$container->set(Validator::class, function () {
    $lang = require VSH_BASE_PATH . '/lang/fr.php';
    return new Validator($lang['validation']);
});

$container->set(ErrorHandler::class, function (Container $c) use ($config) {
    // Le mode debug n'est jamais actif en production, quelle que soit la valeur de APP_DEBUG.
    $debug = (bool) $config->get('app.debug', false) && $config->get('app.env') !== 'production';
    return new ErrorHandler($c->get(Logger::class), $debug);
});

$container->set(Router::class, function () use ($config) {
    $router = new Router();
    $router->group(
        (string) $config->get('app.api_prefix'),
        (array) $config->get('app.middleware.api', []),
        function (Router $router) use ($config) {
            foreach ((array) $config->get('app.modules', []) as $module) {
                $registerRoutes = require VSH_BASE_PATH . '/src/Modules/' . $module . '/routes.php';
                $registerRoutes($router);
            }
        }
    );
    return $router;
});

$container->set(Kernel::class, function (Container $c) use ($config) {
    return new Kernel(
        $c,
        $c->get(Router::class),
        (array) $config->get('app.middleware.global', []),
        (array) $config->get('app.middleware.aliases', [])
    );
});

$container->set(SmsGatewayInterface::class, function (Container $c) use ($config) {
    $driver = (string) $config->get('sms.driver', 'log');
    $sender = (string) $config->get('sms.sender', '');
    switch ($driver) {
        case 'log':
            if ($config->get('app.env') === 'production') {
                throw new \RuntimeException('SMS_DRIVER=log est interdit en production : aucun SMS ne serait envoyé.');
            }
            return new LogSmsGateway($c->get(Logger::class));
        case 'twilio':
            return new TwilioSmsGateway(
                new HttpTransport(),
                (string) $config->get('sms.twilio.account_sid', ''),
                (string) $config->get('sms.twilio.auth_token', ''),
                $sender
            );
        case 'http':
            return new HttpSmsGateway(
                new HttpTransport(),
                (string) $config->get('sms.http.url', ''),
                (string) $config->get('sms.http.method', 'POST'),
                (string) $config->get('sms.http.auth_header', ''),
                (string) $config->get('sms.http.body_template', ''),
                $sender
            );
    }
    throw new \RuntimeException(sprintf('Pilote SMS inconnu : %s (log, twilio ou http).', $driver));
});

// D-011 : construit seulement par le répartiteur des envois, jamais pendant une requête métier.
$container->set(PushGatewayInterface::class, function (Container $c) use ($config) {
    $driver = (string) $config->get('notifications.push_driver', 'none');
    switch ($driver) {
        case 'none':
            return new NullPushGateway();
        case 'log':
            return new LogPushGateway($c->get(Logger::class));
        case 'fcm':
            return FcmPushGateway::fromFile(
                new HttpTransport(),
                (string) $config->get('notifications.fcm.credentials_file', ''),
                (string) $config->get('notifications.fcm.project_id', '')
            );
    }
    throw new \RuntimeException(sprintf('Pilote push inconnu : %s (none, log ou fcm).', $driver));
});

// Entités synchronisables : chaque module les déclare dans src/Modules/<Module>/sync.php.
$container->set(SyncRegistry::class, function (Container $c) use ($config) {
    $registry = new SyncRegistry();
    foreach ((array) $config->get('app.modules', []) as $module) {
        $file = VSH_BASE_PATH . '/src/Modules/' . $module . '/sync.php';
        if (is_file($file)) {
            $register = require $file;
            $register($registry, $c);
        }
    }
    return $registry;
});

$container->set(Migrator::class, function (Container $c) {
    return new Migrator(
        $c->get(Database::class),
        VSH_BASE_PATH . '/database/migrations',
        VSH_BASE_PATH . '/database/seeds'
    );
});

$container->get(ErrorHandler::class)->register();

return $container;
