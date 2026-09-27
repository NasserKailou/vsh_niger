<?php

declare(strict_types=1);

use Vsh\Core\Config;
use Vsh\Core\Http\Kernel;
use Vsh\Core\Http\Request;

$container = require dirname(__DIR__) . '/bootstrap/app.php';

$request = Request::fromGlobals((array) $container->get(Config::class)->get('security.trusted_proxies', []));
$response = $container->get(Kernel::class)->handle($request);
$response->send($request->method() !== 'HEAD');
