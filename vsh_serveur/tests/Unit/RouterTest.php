<?php

declare(strict_types=1);

namespace Vsh\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Vsh\Core\Exceptions\HttpException;
use Vsh\Core\Routing\Router;

final class RouterTest extends TestCase
{
    private const UUID = '0f8e4c2a-9b1d-4e3f-a5c6-7d8e9f0a1b2c';

    public function testMatchesGroupedRouteAndExtractsTypedParameter(): void
    {
        $router = new Router();
        $router->group('/api/v1', ['json_body'], function (Router $router): void {
            $router->get('/patients/{id:uuid}', 'handler', ['auth']);
        });

        $match = $router->match('GET', '/api/v1/patients/' . self::UUID . '/');

        $this->assertSame(['id' => self::UUID], $match['params']);
        $this->assertSame(['json_body', 'auth'], $match['route']['middleware']);
    }

    public function testUuidConstraintRejectsOtherValues(): void
    {
        $router = new Router();
        $router->get('/patients/{id:uuid}', 'handler');

        $this->expectHttpStatus(404, function () use ($router): void {
            $router->match('GET', '/patients/123');
        });
    }

    public function testWrongMethodGives405WithAllowHeader(): void
    {
        $router = new Router();
        $router->get('/health', 'a');
        $router->post('/health', 'b');

        try {
            $router->match('DELETE', '/health');
            $this->fail('Exception attendue');
        } catch (HttpException $exception) {
            $this->assertSame(405, $exception->getStatus());
            $this->assertSame(['Allow' => 'GET, POST'], $exception->getHeaders());
        }
    }

    public function testHeadIsServedByGetRoute(): void
    {
        $router = new Router();
        $router->get('/health', 'handler');

        $this->assertSame('handler', $router->match('HEAD', '/health')['route']['handler']);
    }

    public function testLiteralCharactersInPathAreNotRegex(): void
    {
        $router = new Router();
        $router->get('/reports/daily.csv', 'handler');

        $this->assertSame('handler', $router->match('GET', '/reports/daily.csv')['route']['handler']);
        $this->expectHttpStatus(404, function () use ($router): void {
            $router->match('GET', '/reports/dailyXcsv');
        });
    }

    public function testParametersAreUrlDecoded(): void
    {
        $router = new Router();
        $router->get('/search/{term}', 'handler');

        $this->assertSame(['term' => 'a b'], $router->match('GET', '/search/a%20b')['params']);
    }

    private function expectHttpStatus(int $status, callable $callback): void
    {
        try {
            $callback();
            $this->fail('HttpException attendue');
        } catch (HttpException $exception) {
            $this->assertSame($status, $exception->getStatus());
        }
    }
}
