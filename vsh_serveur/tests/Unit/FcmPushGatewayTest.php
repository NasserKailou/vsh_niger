<?php

declare(strict_types=1);

namespace Vsh\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Vsh\Core\Push\FcmPushGateway;
use Vsh\Core\Push\PushException;
use Vsh\Core\Push\PushGatewayInterface;
use Vsh\Core\Push\PushMessage;
use Vsh\Core\Support\HttpTransport;
use Vsh\Core\Support\TransportException;

/**
 * FCM HTTP v1 sans réseau : JWT du compte de service, jeton OAuth gardé en mémoire, message envoyé,
 * interprétation des réponses (jeton invalide, erreur temporaire).
 */
final class FcmPushGatewayTest extends TestCase
{
    /** @var string|null */
    private static $privateKey;

    /** @var string */
    private static $publicKey;

    public static function setUpBeforeClass(): void
    {
        // Sous Windows, PHP a besoin de son openssl.cnf pour générer une clé.
        $options = ['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA];
        $config = getenv('OPENSSL_CONF') ?: dirname(PHP_BINARY) . '/extras/ssl/openssl.cnf';
        if (is_file($config)) {
            $options['config'] = $config;
        }
        $key = openssl_pkey_new($options);
        if ($key === false) {
            return;
        }
        openssl_pkey_export($key, $privateKey, null, $options);
        self::$privateKey = (string) $privateKey;
        self::$publicKey = (string) openssl_pkey_get_details($key)['key'];
    }

    protected function setUp(): void
    {
        if (self::$privateKey === null) {
            $this->markTestSkipped('Génération de clé RSA impossible (openssl.cnf introuvable).');
        }
    }

    public function testSignsAServiceAccountJwtAndSendsTheMessage(): void
    {
        $transport = new RecordingTransport([[200, '{"access_token":"ya29.test","expires_in":3600}'], [200, '{"name":"projects/vsh/messages/1"}']]);
        $gateway = $this->gateway($transport);

        $result = $gateway->send('jeton-appareil', new PushMessage('Résultat disponible', 'Un résultat d\'examen est disponible dans votre dossier.', ['type' => 'EXAM_RESULT']));
        $this->assertSame(PushGatewayInterface::SENT, $result);

        list($tokenRequest, $sendRequest) = $transport->requests;
        $this->assertSame('https://oauth2.googleapis.com/token', $tokenRequest['url']);
        parse_str($tokenRequest['body'], $form);
        $this->assertSame('urn:ietf:params:oauth:grant-type:jwt-bearer', $form['grant_type']);
        list($header, $claims, $signature) = explode('.', (string) $form['assertion']);
        $this->assertSame(1, openssl_verify($header . '.' . $claims, self::base64UrlDecode($signature), self::$publicKey, OPENSSL_ALGO_SHA256));
        $claims = json_decode(self::base64UrlDecode($claims), true);
        $this->assertSame('vsh@vsh-test.iam.gserviceaccount.com', $claims['iss']);
        $this->assertSame('https://www.googleapis.com/auth/firebase.messaging', $claims['scope']);
        $this->assertSame(3600, $claims['exp'] - $claims['iat']);

        $this->assertSame('https://fcm.googleapis.com/v1/projects/vsh-test/messages:send', $sendRequest['url']);
        $this->assertSame('Bearer ya29.test', $sendRequest['headers']['Authorization']);
        $message = json_decode($sendRequest['body'], true)['message'];
        $this->assertSame('jeton-appareil', $message['token']);
        $this->assertSame('Résultat disponible', $message['notification']['title']);
        $this->assertSame(['type' => 'EXAM_RESULT'], $message['data']);
        $this->assertSame('vsh_notifications', $message['android']['notification']['channel_id']);
    }

    public function testReusesTheOauthTokenAcrossMessages(): void
    {
        $transport = new RecordingTransport([[200, '{"access_token":"ya29.test","expires_in":3600}'], [200, '{}'], [200, '{}']]);
        $gateway = $this->gateway($transport);
        $gateway->send('a', new PushMessage('T', 'B'));
        $gateway->send('b', new PushMessage('T', 'B'));
        $this->assertCount(3, $transport->requests, 'Un seul échange de jeton OAuth');
    }

    public function testRenewsARevokedOauthTokenOnce(): void
    {
        $transport = new RecordingTransport([
            [200, '{"access_token":"ancien","expires_in":3600}'],
            [401, '{"error":{"status":"UNAUTHENTICATED"}}'],
            [200, '{"access_token":"nouveau","expires_in":3600}'],
            [200, '{}'],
        ]);
        $this->assertSame(PushGatewayInterface::SENT, $this->gateway($transport)->send('a', new PushMessage('T', 'B')));
        $this->assertSame('Bearer nouveau', $transport->requests[3]['headers']['Authorization']);
    }

    /**
     * @dataProvider invalidTokenResponses
     */
    public function testReportsInvalidTokens(int $status, string $body): void
    {
        $transport = new RecordingTransport([[200, '{"access_token":"t","expires_in":3600}'], [$status, $body]]);
        $this->assertSame(PushGatewayInterface::INVALID_TOKEN, $this->gateway($transport)->send('a', new PushMessage('T', 'B')));
    }

    public function invalidTokenResponses(): array
    {
        return [
            'désinstallée' => [404, '{"error":{"status":"NOT_FOUND","details":[{"errorCode":"UNREGISTERED"}]}}'],
            'mal formé' => [400, '{"error":{"message":"The registration token is not a valid FCM registration token","status":"INVALID_ARGUMENT"}}'],
            'autre projet' => [403, '{"error":{"details":[{"errorCode":"SENDER_ID_MISMATCH"}]}}'],
        ];
    }

    /**
     * @dataProvider temporaryFailures
     */
    public function testTemporaryFailuresAreRetried(int $status, string $body): void
    {
        $transport = new RecordingTransport([[200, '{"access_token":"t","expires_in":3600}'], [$status, $body]]);
        $this->expectException(PushException::class);
        $this->gateway($transport)->send('a', new PushMessage('T', 'B'));
    }

    public function temporaryFailures(): array
    {
        return [
            'quota' => [429, '{"error":{"status":"RESOURCE_EXHAUSTED"}}'],
            'service' => [503, '{"error":{"status":"UNAVAILABLE"}}'],
            'message refusé, jeton conservé' => [400, '{"error":{"message":"Invalid JSON payload","status":"INVALID_ARGUMENT"}}'],
        ];
    }

    public function testUnreachableServiceIsATemporaryFailure(): void
    {
        $transport = new RecordingTransport([]);
        $this->expectException(PushException::class);
        $this->gateway($transport)->send('a', new PushMessage('T', 'B'));
    }

    public function testRejectsIncompleteServiceAccount(): void
    {
        $this->expectException(\RuntimeException::class);
        new FcmPushGateway(new RecordingTransport([]), ['client_email' => 'x@y.z', 'project_id' => 'p']);
    }

    private function gateway(RecordingTransport $transport): FcmPushGateway
    {
        return new FcmPushGateway($transport, [
            'type' => 'service_account',
            'project_id' => 'vsh-test',
            'client_email' => 'vsh@vsh-test.iam.gserviceaccount.com',
            'private_key' => self::$privateKey,
            'token_uri' => 'https://oauth2.googleapis.com/token',
        ]);
    }

    private static function base64UrlDecode(string $value): string
    {
        return (string) base64_decode(strtr($value, '-_', '+/'));
    }
}

/**
 * Transport simulé : réponses prédéfinies dans l'ordre, serveur injoignable quand il n'y en a plus.
 */
final class RecordingTransport extends HttpTransport
{
    /** @var array<int,array{url: string, headers: array, body: string}> */
    public $requests = [];

    /** @var array<int,array{0: int, 1: string}> */
    private $responses;

    public function __construct(array $responses)
    {
        $this->responses = $responses;
    }

    public function request(string $method, string $url, array $headers, string $body, int $timeoutSeconds = 10): array
    {
        $this->requests[] = ['url' => $url, 'headers' => $headers, 'body' => $body];
        if ($this->responses === []) {
            throw new TransportException('Serveur distant injoignable (test).');
        }
        return array_shift($this->responses);
    }
}
