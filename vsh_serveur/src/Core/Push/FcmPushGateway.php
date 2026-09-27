<?php

declare(strict_types=1);

namespace Vsh\Core\Push;

use Vsh\Core\Support\HttpTransport;
use Vsh\Core\Support\TransportException;

/**
 * Firebase Cloud Messaging, API HTTP v1, sans dépendance.
 *
 * Authentification par compte de service : un JWT signé RS256 (clé privée du fichier JSON téléchargé
 * dans la console Firebase) est échangé contre un jeton OAuth2 d'une heure, gardé en mémoire.
 * Le chemin du fichier est donné par FCM_CREDENTIALS_FILE ; il ne doit jamais être versionné.
 */
final class FcmPushGateway implements PushGatewayInterface
{
    private const SCOPE = 'https://www.googleapis.com/auth/firebase.messaging';

    /** @var HttpTransport */
    private $transport;

    /** @var string */
    private $projectId;

    /** @var string */
    private $clientEmail;

    /** @var string */
    private $privateKey;

    /** @var string */
    private $tokenUri;

    /** @var string|null */
    private $accessToken;

    /** @var int */
    private $accessTokenExpiresAt = 0;

    /**
     * @param array $credentials Contenu du fichier JSON du compte de service
     */
    public function __construct(HttpTransport $transport, array $credentials, string $projectId = '')
    {
        foreach (['client_email', 'private_key'] as $field) {
            if (!is_string($credentials[$field] ?? null) || $credentials[$field] === '') {
                throw new \RuntimeException('Compte de service FCM invalide : champ « ' . $field . ' » manquant.');
            }
        }
        $this->projectId = $projectId !== '' ? $projectId : (string) ($credentials['project_id'] ?? '');
        if ($this->projectId === '') {
            throw new \RuntimeException('Projet Firebase inconnu (FCM_PROJECT_ID ou project_id du compte de service).');
        }
        $this->transport = $transport;
        $this->clientEmail = (string) $credentials['client_email'];
        $this->privateKey = (string) $credentials['private_key'];
        $this->tokenUri = (string) ($credentials['token_uri'] ?? 'https://oauth2.googleapis.com/token');
    }

    public static function fromFile(HttpTransport $transport, string $path, string $projectId = ''): self
    {
        if ($path === '' || !is_readable($path)) {
            throw new \RuntimeException('Fichier du compte de service FCM illisible (FCM_CREDENTIALS_FILE).');
        }
        $credentials = json_decode((string) file_get_contents($path), true);
        if (!is_array($credentials)) {
            throw new \RuntimeException('Fichier du compte de service FCM invalide (JSON attendu).');
        }
        return new self($transport, $credentials, $projectId);
    }

    public function enabled(): bool
    {
        return true;
    }

    public function send(string $token, PushMessage $message): string
    {
        list($status, $body) = $this->post($token, $message);
        if ($status === 401) {
            // Jeton OAuth révoqué avant son expiration : un seul nouvel essai avec un jeton neuf.
            $this->accessToken = null;
            list($status, $body) = $this->post($token, $message);
        }
        if ($status >= 200 && $status < 300) {
            return self::SENT;
        }
        if (self::isInvalidToken($status, $body)) {
            return self::INVALID_TOKEN;
        }
        throw new PushException(sprintf('FCM a refusé l\'envoi (HTTP %d).', $status));
    }

    /**
     * 404 UNREGISTERED : application désinstallée ; 403 SENDER_ID_MISMATCH : jeton d'un autre projet ;
     * 400 INVALID_ARGUMENT portant sur le jeton. Un 400 dû au message lui-même ne supprime pas le jeton.
     */
    private static function isInvalidToken(int $status, string $body): bool
    {
        if ($status === 404) {
            return true;
        }
        if ($status === 403) {
            return strpos($body, 'SENDER_ID_MISMATCH') !== false;
        }
        return $status === 400 && stripos($body, 'registration token') !== false;
    }

    /**
     * @return array{0: int, 1: string}
     */
    private function post(string $token, PushMessage $message): array
    {
        $payload = [
            'message' => [
                'token' => $token,
                'notification' => ['title' => $message->title(), 'body' => $message->body()],
                'data' => (object) $message->data(),
                'android' => [
                    'priority' => 'HIGH',
                    'notification' => ['channel_id' => 'vsh_notifications'],
                ],
                'apns' => [
                    'payload' => ['aps' => ['sound' => 'default']],
                ],
            ],
        ];
        $url = sprintf('https://fcm.googleapis.com/v1/projects/%s/messages:send', rawurlencode($this->projectId));
        try {
            return $this->transport->request('POST', $url, [
                'Authorization' => 'Bearer ' . $this->accessToken(),
                'Content-Type' => 'application/json; charset=UTF-8',
            ], (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        } catch (TransportException $exception) {
            throw new PushException('Service FCM injoignable.', 0, $exception);
        }
    }

    private function accessToken(): string
    {
        if ($this->accessToken !== null && time() < $this->accessTokenExpiresAt - 60) {
            return $this->accessToken;
        }
        $now = time();
        $assertion = $this->jwt([
            'iss' => $this->clientEmail,
            'scope' => self::SCOPE,
            'aud' => $this->tokenUri,
            'iat' => $now,
            'exp' => $now + 3600,
        ]);
        try {
            list($status, $body) = $this->transport->request('POST', $this->tokenUri, [
                'Content-Type' => 'application/x-www-form-urlencoded',
            ], http_build_query([
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $assertion,
            ]));
        } catch (TransportException $exception) {
            throw new PushException('Service d\'authentification Google injoignable.', 0, $exception);
        }
        $response = json_decode($body, true);
        if ($status !== 200 || !is_array($response) || !is_string($response['access_token'] ?? null)) {
            throw new PushException(sprintf('Authentification FCM refusée (HTTP %d).', $status));
        }
        $this->accessToken = $response['access_token'];
        $this->accessTokenExpiresAt = $now + (int) ($response['expires_in'] ?? 3600);
        return $this->accessToken;
    }

    private function jwt(array $claims): string
    {
        $segments = [
            self::base64Url((string) json_encode(['alg' => 'RS256', 'typ' => 'JWT'])),
            self::base64Url((string) json_encode($claims, JSON_UNESCAPED_SLASHES)),
        ];
        $key = openssl_pkey_get_private($this->privateKey);
        if ($key === false) {
            throw new \RuntimeException('Clé privée du compte de service FCM illisible.');
        }
        $signature = '';
        if (!openssl_sign(implode('.', $segments), $signature, $key, OPENSSL_ALGO_SHA256)) {
            throw new \RuntimeException('Signature du jeton FCM impossible.');
        }
        $segments[] = self::base64Url($signature);
        return implode('.', $segments);
    }

    private static function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
