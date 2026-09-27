<?php

declare(strict_types=1);

namespace Vsh\Core\Support;

/**
 * Requête HTTP(S) sortante minimale, sans dépendance (flux PHP natifs, certificat TLS vérifié).
 * Utilisée par les passerelles SMS et push.
 */
class HttpTransport
{
    /**
     * @param array<string,string> $headers
     * @return array{0: int, 1: string} Code HTTP et corps de la réponse
     * @throws TransportException Si le serveur distant est injoignable
     */
    public function request(string $method, string $url, array $headers, string $body, int $timeoutSeconds = 10): array
    {
        $headerLines = [];
        foreach ($headers as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }
        $context = stream_context_create([
            'http' => [
                'method' => strtoupper($method),
                'header' => implode("\r\n", $headerLines),
                'content' => $body,
                'timeout' => $timeoutSeconds,
                'ignore_errors' => true,
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);

        $response = @file_get_contents($url, false, $context);
        if ($response === false) {
            throw new TransportException('Serveur distant injoignable : ' . (string) parse_url($url, PHP_URL_HOST));
        }
        $status = 0;
        foreach ($http_response_header ?? [] as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $matches) === 1) {
                $status = (int) $matches[1];
            }
        }
        return [$status, $response];
    }
}
