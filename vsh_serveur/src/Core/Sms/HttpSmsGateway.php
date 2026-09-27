<?php

declare(strict_types=1);

namespace Vsh\Core\Sms;

use Vsh\Core\Support\HttpTransport;
use Vsh\Core\Support\TransportException;

/**
 * Agrégateur SMS générique (fournisseur local), entièrement paramétré par l'environnement :
 * SMS_HTTP_URL, SMS_HTTP_METHOD, SMS_HTTP_AUTH_HEADER (valeur de l'en-tête Authorization)
 * et SMS_HTTP_BODY_TEMPLATE, un modèle JSON contenant {to}, {sender} et {message}.
 */
final class HttpSmsGateway implements SmsGatewayInterface
{
    /** @var HttpTransport */
    private $transport;

    /** @var string */
    private $url;

    /** @var string */
    private $method;

    /** @var string */
    private $authorization;

    /** @var string */
    private $bodyTemplate;

    /** @var string */
    private $sender;

    public function __construct(
        HttpTransport $transport,
        string $url,
        string $method,
        string $authorization,
        string $bodyTemplate,
        string $sender
    ) {
        if ($url === '' || $bodyTemplate === '') {
            throw new \RuntimeException('Configuration SMS HTTP incomplète (SMS_HTTP_URL, SMS_HTTP_BODY_TEMPLATE).');
        }
        $this->transport = $transport;
        $this->url = $url;
        $this->method = $method;
        $this->authorization = $authorization;
        $this->bodyTemplate = $bodyTemplate;
        $this->sender = $sender;
    }

    public function send(string $to, string $message): void
    {
        $body = strtr($this->bodyTemplate, [
            '{to}' => self::jsonString($to),
            '{sender}' => self::jsonString($this->sender),
            '{message}' => self::jsonString($message),
        ]);
        $headers = ['Content-Type' => 'application/json', 'Accept' => 'application/json'];
        if ($this->authorization !== '') {
            $headers['Authorization'] = $this->authorization;
        }

        try {
            try {
            list($status) = $this->transport->request($this->method, $this->url, $headers, $body);
        } catch (TransportException $exception) {
            throw new SmsException('Passerelle SMS injoignable.', 0, $exception);
        }
        } catch (TransportException $exception) {
            throw new SmsException('Passerelle SMS injoignable.', 0, $exception);
        }
        if ($status < 200 || $status >= 300) {
            throw new SmsException(sprintf('La passerelle SMS a refusé l\'envoi (HTTP %d).', $status));
        }
    }

    /**
     * Valeur échappée pour être insérée entre guillemets dans le modèle JSON.
     */
    private static function jsonString(string $value): string
    {
        return substr((string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 1, -1);
    }
}
