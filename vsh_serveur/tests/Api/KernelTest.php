<?php

declare(strict_types=1);

namespace Vsh\Tests\Api;

use PHPUnit\Framework\TestCase;
use Vsh\Core\Config;
use Vsh\Core\Container;
use Vsh\Core\ErrorHandler;
use Vsh\Core\Exceptions\ValidationException;
use Vsh\Core\Http\ApiResponse;
use Vsh\Core\Http\Kernel;
use Vsh\Core\Http\Request;
use Vsh\Core\Http\Response;
use Vsh\Core\Logger;
use Vsh\Core\Routing\Router;

/**
 * Tests de bout en bout du cycle requête → réponse (sans serveur web).
 */
final class KernelTest extends TestCase
{
    /** @var Container */
    private $container;

    /** @var string */
    private $logDirectory;

    protected function setUp(): void
    {
        $this->container = require dirname(__DIR__, 2) . '/bootstrap/app.php';
        $this->logDirectory = sys_get_temp_dir() . '/vsh-kernel-tests';

        // Journal dans un dossier temporaire, et comportement de production (sans détails d'erreur).
        $this->container->set(Logger::class, function (): Logger {
            return new Logger($this->logDirectory, 'debug');
        });
        $this->container->set(ErrorHandler::class, function (Container $c): ErrorHandler {
            return new ErrorHandler($c->get(Logger::class), false);
        });

        $router = $this->container->get(Router::class);
        $router->post('/api/v1/_test/echo', function (Request $request): Response {
            return ApiResponse::success($request->json());
        }, ['json_body']);
        $router->get('/api/v1/_test/crash', function (): Response {
            throw new \RuntimeException('Détail interne : SELECT * FROM patients');
        });
        $router->get('/api/v1/_test/invalid', function (): Response {
            throw new ValidationException(['phone' => ['Numéro de téléphone invalide.']]);
        });
    }

    protected function tearDown(): void
    {
        restore_error_handler();
    }

    public function testHealthReturnsStandardEnvelopeAndSecurityHeaders(): void
    {
        $response = $this->send('GET', '/api/v1/health');
        $payload = $this->decode($response);

        $this->assertSame(200, $response->status());
        $this->assertTrue($payload['success']);
        $this->assertSame('ok', $payload['data']['status']);
        $this->assertSame('ok', $payload['data']['database']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $payload['data']['server_time']);
        $this->assertSame('application/json; charset=utf-8', $response->header('Content-Type'));
        $this->assertSame('no-store', $response->header('Cache-Control'));
        $this->assertSame('nosniff', $response->header('X-Content-Type-Options'));
        $this->assertNotNull($response->header('X-Request-Id'));
    }

    public function testUnknownRouteReturns404WithEmptyErrorsObject(): void
    {
        $response = $this->send('GET', '/api/v1/nothing-here');

        $this->assertSame(404, $response->status());
        $this->assertSame('NOT_FOUND', $this->decode($response)['code']);
        $this->assertStringContainsString('"errors":{}', $response->body());
        $this->assertNotNull($response->header('X-Request-Id'), 'Les réponses d\'erreur portent aussi l\'identifiant de requête');
    }

    public function testWrongMethodReturns405WithAllowHeader(): void
    {
        $response = $this->send('DELETE', '/api/v1/health');

        $this->assertSame(405, $response->status());
        $this->assertSame('GET', $response->header('Allow'));
    }

    public function testValidJsonIsAccepted(): void
    {
        $response = $this->send('POST', '/api/v1/_test/echo', '{"name":"Aïcha"}', ['Content-Type' => 'application/json']);

        $this->assertSame(200, $response->status());
        $this->assertSame(['name' => 'Aïcha'], $this->decode($response)['data']);
    }

    public function testMalformedJsonReturns400(): void
    {
        $response = $this->send('POST', '/api/v1/_test/echo', '{"name":', ['Content-Type' => 'application/json']);

        $this->assertSame(400, $response->status());
        $this->assertSame('INVALID_JSON', $this->decode($response)['code']);
    }

    public function testNonJsonContentTypeReturns415(): void
    {
        $response = $this->send('POST', '/api/v1/_test/echo', 'name=x', ['Content-Type' => 'application/x-www-form-urlencoded']);

        $this->assertSame(415, $response->status());
    }

    public function testOversizedBodyReturns413(): void
    {
        $this->container->get(Config::class)->set('app.max_json_bytes', 16);

        $response = $this->send('POST', '/api/v1/_test/echo', '{"name":"' . str_repeat('a', 32) . '"}', ['Content-Type' => 'application/json']);

        $this->assertSame(413, $response->status());
    }

    public function testUnexpectedExceptionIsHiddenFromClientButLogged(): void
    {
        $response = $this->send('GET', '/api/v1/_test/crash', '', ['X-Request-Id' => 'test-request-0001']);
        $payload = $this->decode($response);

        $this->assertSame(500, $response->status());
        $this->assertSame('SERVER_ERROR', $payload['code']);
        $this->assertArrayNotHasKey('debug', $payload);
        $this->assertStringNotContainsString('SELECT', $response->body());
        $this->assertSame('test-request-0001', $response->header('X-Request-Id'));

        $log = (string) file_get_contents($this->logDirectory . '/app-' . gmdate('Y-m-d') . '.log');
        $this->assertStringContainsString('test-request-0001', $log);
        $this->assertStringContainsString('SELECT * FROM patients', $log);
    }

    public function testValidationErrorsUseStandardFormat(): void
    {
        $response = $this->send('GET', '/api/v1/_test/invalid');
        $payload = $this->decode($response);

        $this->assertSame(422, $response->status());
        $this->assertFalse($payload['success']);
        $this->assertSame('VALIDATION_ERROR', $payload['code']);
        $this->assertSame(['phone' => ['Numéro de téléphone invalide.']], $payload['errors']);
    }

    public function testMalformedRequestIdIsReplaced(): void
    {
        $response = $this->send('GET', '/api/v1/health', '', ['X-Request-Id' => "bad\nvalue"]);

        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', (string) $response->header('X-Request-Id'));
    }

    public function testCorsPreflightOnlyForAllowedOrigins(): void
    {
        $this->container->get(Config::class)->set('security.cors.allowed_origins', ['https://admin.exemple.ne']);
        $headers = ['Access-Control-Request-Method' => 'POST'];

        $allowed = $this->send('OPTIONS', '/api/v1/health', '', $headers + ['Origin' => 'https://admin.exemple.ne']);
        $denied = $this->send('OPTIONS', '/api/v1/health', '', $headers + ['Origin' => 'https://malveillant.exemple']);

        $this->assertSame(204, $allowed->status());
        $this->assertSame('https://admin.exemple.ne', $allowed->header('Access-Control-Allow-Origin'));
        $this->assertNull($denied->header('Access-Control-Allow-Origin'));
    }

    private function send(string $method, string $path, string $body = '', array $headers = []): Response
    {
        $request = new Request($method, $path, [], $headers, $body, ['REMOTE_ADDR' => '127.0.0.1']);
        return $this->container->get(Kernel::class)->handle($request);
    }

    private function decode(Response $response): array
    {
        $payload = json_decode($response->body(), true);
        $this->assertIsArray($payload, 'Réponse JSON attendue');
        return $payload;
    }
}
