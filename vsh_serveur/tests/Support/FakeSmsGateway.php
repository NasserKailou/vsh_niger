<?php

declare(strict_types=1);

namespace Vsh\Tests\Support;

use Vsh\Core\Sms\SmsException;
use Vsh\Core\Sms\SmsGatewayInterface;

final class FakeSmsGateway implements SmsGatewayInterface
{
    /** @var array<int,array{to: string, message: string}> */
    public $sent = [];

    /** @var bool */
    public $failing = false;

    public function send(string $to, string $message): void
    {
        if ($this->failing) {
            throw new SmsException('Passerelle indisponible (test).');
        }
        $this->sent[] = ['to' => $to, 'message' => $message];
    }

    public function lastCodeFor(string $phone): ?string
    {
        foreach (array_reverse($this->sent) as $sms) {
            if ($sms['to'] === $phone && preg_match('/\b(\d{6})\b/', $sms['message'], $matches) === 1) {
                return $matches[1];
            }
        }
        return null;
    }
}
