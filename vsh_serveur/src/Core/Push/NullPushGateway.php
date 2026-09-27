<?php

declare(strict_types=1);

namespace Vsh\Core\Push;

/**
 * Push désactivé : les notifications restent disponibles dans l'application et au pull.
 */
final class NullPushGateway implements PushGatewayInterface
{
    public function enabled(): bool
    {
        return false;
    }

    public function send(string $token, PushMessage $message): string
    {
        throw new \LogicException('Push désactivé (PUSH_DRIVER=none).');
    }
}
