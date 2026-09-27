<?php

declare(strict_types=1);

namespace Vsh\Core\Sms;

use Vsh\Core\Support\HttpTransport;
use Vsh\Core\Support\TransportException;

final class TwilioSmsGateway implements SmsGatewayInterface
{
    /** @var HttpTransport */
    private $transport;

    /** @var string */
    private $accountSid;

    /** @var string */
    private $authToken;

    /** @var string */
    private $sender;

    public function __construct(HttpTransport $transport, string $accountSid, string $authToken, string $sender)
    {
        if ($accountSid === '' || $authToken === '' || $sender === '') {
            throw new \RuntimeException('Configuration Twilio incomplète (TWILIO_ACCOUNT_SID, TWILIO_AUTH_TOKEN, SMS_SENDER).');
        }
        $this->transport = $transport;
        $this->accountSid = $accountSid;
        $this->authToken = $authToken;
        $this->sender = $sender;
    }

    public function send(string $to, string $message): void
    {
        $url = sprintf('https://api.twilio.com/2010-04-01/Accounts/%s/Messages.json', rawurlencode($this->accountSid));
        try {
            list($status) = $this->transport->request('POST', $url, [
                'Authorization' => 'Basic ' . base64_encode($this->accountSid . ':' . $this->authToken),
                'Content-Type' => 'application/x-www-form-urlencoded',
            ], http_build_query(['To' => $to, 'From' => $this->sender, 'Body' => $message]));
        } catch (TransportException $exception) {
            throw new SmsException('Passerelle SMS injoignable.', 0, $exception);
        }

        if ($status < 200 || $status >= 300) {
            throw new SmsException(sprintf('Twilio a refusé l\'envoi (HTTP %d).', $status));
        }
    }
}
