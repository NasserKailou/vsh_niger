<?php

declare(strict_types=1);

use Vsh\Core\Env;

// D-002 : passerelle SMS abstraite. Les identifiants ne sont lus que depuis l'environnement.
return [
    'driver' => Env::get('SMS_DRIVER', 'log'),
    'sender' => Env::get('SMS_SENDER', ''),
    'twilio' => [
        'account_sid' => Env::get('TWILIO_ACCOUNT_SID', ''),
        'auth_token' => Env::get('TWILIO_AUTH_TOKEN', ''),
    ],
    'http' => [
        'url' => Env::get('SMS_HTTP_URL', ''),
        'method' => Env::get('SMS_HTTP_METHOD', 'POST'),
        'auth_header' => Env::get('SMS_HTTP_AUTH_HEADER', ''),
        'body_template' => Env::get('SMS_HTTP_BODY_TEMPLATE', '{"to":"{to}","from":"{sender}","message":"{message}"}'),
    ],
];
