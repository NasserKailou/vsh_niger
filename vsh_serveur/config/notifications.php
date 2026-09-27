<?php

declare(strict_types=1);

use Vsh\Core\Env;

// D-011 : envoi des notifications par push et SMS. Les réglages métier (activation du push,
// mode et types de SMS) sont des paramètres modifiables par l'administrateur (table settings).
return [
    // none | log | fcm
    'push_driver' => Env::get('PUSH_DRIVER', 'none'),
    'fcm' => [
        // Fichier JSON du compte de service Firebase, hors du dossier public et jamais versionné.
        'credentials_file' => Env::get('FCM_CREDENTIALS_FILE', ''),
        // Facultatif : par défaut, project_id du compte de service.
        'project_id' => Env::get('FCM_PROJECT_ID', ''),
    ],
    // Tentatives avant abandon, puis délais (minutes) entre deux tentatives.
    'max_attempts' => Env::int('NOTIFY_MAX_ATTEMPTS', 5),
    'retry_delays_minutes' => [1, 5, 15, 60],
    // Au-delà, un push ou un SMS n'a plus de sens (« l'équipe est en route » envoyé le lendemain).
    'expire_after_hours' => Env::int('NOTIFY_EXPIRE_HOURS', 12),
    'batch_size' => 100,
];
