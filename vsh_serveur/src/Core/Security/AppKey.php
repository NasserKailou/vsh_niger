<?php

declare(strict_types=1);

namespace Vsh\Core\Security;

use Vsh\Core\Config;

final class AppKey
{
    /**
     * Clé secrète de l'application (HMAC des codes OTP et des identifiants de limitation).
     */
    public static function from(Config $config): string
    {
        $key = (string) $config->get('security.app_key', '');
        if (strlen($key) < 32) {
            throw new \RuntimeException('APP_KEY absente ou trop courte : générez-la avec « php bin/console.php key:generate ».');
        }
        return $key;
    }
}
