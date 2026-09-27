<?php

declare(strict_types=1);

namespace Vsh\Core\Push;

use Vsh\Core\Logger;

/**
 * Pilote de développement : le message est écrit dans le journal au lieu d'être envoyé.
 */
final class LogPushGateway implements PushGatewayInterface
{
    /** @var Logger */
    private $logger;

    public function __construct(Logger $logger)
    {
        $this->logger = $logger;
    }

    public function enabled(): bool
    {
        return true;
    }

    public function send(string $token, PushMessage $message): string
    {
        $this->logger->info('Push non envoyé (pilote log)', [
            'push_title' => $message->title(),
            'push_text' => $message->body(),
            'push_data' => $message->data(),
        ]);
        return self::SENT;
    }
}
