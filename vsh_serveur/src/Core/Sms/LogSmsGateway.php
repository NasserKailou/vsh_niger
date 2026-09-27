<?php

declare(strict_types=1);

namespace Vsh\Core\Sms;

use Vsh\Core\Logger;

/**
 * Pilote de développement : le SMS est écrit dans le journal au lieu d'être envoyé.
 * Refusé en production (voir bootstrap/app.php).
 */
final class LogSmsGateway implements SmsGatewayInterface
{
    /** @var Logger */
    private $logger;

    public function __construct(Logger $logger)
    {
        $this->logger = $logger;
    }

    public function send(string $to, string $message): void
    {
        $this->logger->info('SMS non envoyé (pilote log)', ['to' => $to, 'sms_text' => $message]);
    }
}
