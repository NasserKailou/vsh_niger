<?php

declare(strict_types=1);

namespace Vsh\Tests\Support;

use Vsh\Core\Push\PushException;
use Vsh\Core\Push\PushGatewayInterface;
use Vsh\Core\Push\PushMessage;

final class FakePushGateway implements PushGatewayInterface
{
    /** @var array<int,array{token: string, message: PushMessage}> */
    public $sent = [];

    /** @var array<string,string> Réponse imposée par jeton : INVALID_TOKEN ou FAIL (échec temporaire) */
    public $responses = [];

    public function enabled(): bool
    {
        return true;
    }

    public function send(string $token, PushMessage $message): string
    {
        $response = $this->responses[$token] ?? self::SENT;
        if ($response === 'FAIL') {
            throw new PushException('Service indisponible (test).');
        }
        if ($response === self::SENT) {
            $this->sent[] = ['token' => $token, 'message' => $message];
        }
        return $response;
    }
}
