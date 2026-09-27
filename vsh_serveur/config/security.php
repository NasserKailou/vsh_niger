<?php

declare(strict_types=1);

use Vsh\Core\Env;

return [
    // Clé secrète (HMAC des codes OTP, etc.). Obligatoire en production.
    'app_key' => Env::get('APP_KEY', ''),

    'access_token_ttl' => Env::int('ACCESS_TOKEN_TTL', 3600),
    'refresh_token_ttl_days' => Env::int('REFRESH_TOKEN_TTL_DAYS', 30),
    // Délai pendant lequel un refresh token déjà utilisé peut être représenté si la réponse a été perdue
    'refresh_reuse_grace_seconds' => 120,

    // Échecs tolérés sur la fenêtre, par identifiant (téléphone) puis par adresse IP
    'login' => [
        'max_failures' => 5,
        'window_minutes' => 15,
        'ip_max_failures' => 30,
    ],

    'otp' => [
        'length' => 6,
        'ttl_minutes' => 5,
        'max_attempts' => 5,
        'resend_cooldown_seconds' => 60,
        'max_per_hour' => 5,
    ],

    // En-tête Strict-Transport-Security sur les requêtes HTTPS
    'hsts' => Env::get('APP_ENV', 'production') === 'production',

    // X-Forwarded-For / X-Forwarded-Proto ne sont pris en compte que depuis ces adresses
    'trusted_proxies' => Env::csv('TRUSTED_PROXIES'),

    'cors' => [
        'allowed_origins' => Env::csv('CORS_ALLOWED_ORIGINS'),
    ],

    // Indicatif utilisé pour normaliser les numéros saisis sans indicatif (Niger : 227)
    'default_country_code' => Env::get('DEFAULT_COUNTRY_CODE', '227'),
];
