<?php

declare(strict_types=1);

namespace Vsh\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Vsh\Core\Config;
use Vsh\Core\Container;
use Vsh\Core\Support\Clock;
use Vsh\Core\Support\Uuid;

final class SupportTest extends TestCase
{
    protected function tearDown(): void
    {
        Clock::setTestNow(null);
    }

    public function testUuidV4IsValidAndUnique(): void
    {
        $first = Uuid::v4();
        $second = Uuid::v4();

        $this->assertTrue(Uuid::isValid($first));
        $this->assertSame('4', $first[14]);
        $this->assertContains($first[19], ['8', '9', 'a', 'b']);
        $this->assertNotSame($first, $second);
    }

    public function testClockIsUtcAndCanBeFrozen(): void
    {
        Clock::setTestNow(new \DateTimeImmutable('2026-09-25 10:00:00', new \DateTimeZone('Africa/Niamey')));

        $this->assertSame('2026-09-25 09:00:00', Clock::nowForDatabase());
        $this->assertSame('UTC', Clock::now()->getTimezone()->getName());
    }

    public function testConfigDotNotation(): void
    {
        $config = new Config(['app' => ['middleware' => ['global' => ['A']]]]);
        $config->set('security.cors.allowed_origins', ['https://admin.exemple.ne']);

        $this->assertSame(['A'], $config->get('app.middleware.global'));
        $this->assertSame(['https://admin.exemple.ne'], $config->get('security.cors.allowed_origins'));
        $this->assertSame('défaut', $config->get('app.missing', 'défaut'));
    }

    public function testContainerAutowiresAndSharesInstances(): void
    {
        $container = new Container();
        $container->instance(Config::class, new Config([]));

        $service = $container->get(SupportTestService::class);

        $this->assertSame($service, $container->get(SupportTestService::class));
        $this->assertSame($container->get(Config::class), $service->config);
    }
}

final class SupportTestService
{
    /** @var Config */
    public $config;

    public function __construct(Config $config)
    {
        $this->config = $config;
    }
}
