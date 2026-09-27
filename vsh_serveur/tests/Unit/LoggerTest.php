<?php

declare(strict_types=1);

namespace Vsh\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Vsh\Core\Logger;

final class LoggerTest extends TestCase
{
    /** @var string */
    private $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/vsh-logger-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            unlink($file);
        }
        if (is_dir($this->directory)) {
            rmdir($this->directory);
        }
    }

    public function testRedactsSensitiveKeysRecursively(): void
    {
        $redacted = Logger::redact([
            'phone' => '+22790000000',
            'password' => 'secret',
            'nested' => ['refresh_token' => 'abc', 'otp' => '123456', 'status' => 'ok'],
            'Authorization' => 'Bearer x',
        ]);

        $this->assertSame('+22790000000', $redacted['phone']);
        $this->assertSame('[MASQUÉ]', $redacted['password']);
        $this->assertSame('[MASQUÉ]', $redacted['nested']['refresh_token']);
        $this->assertSame('[MASQUÉ]', $redacted['nested']['otp']);
        $this->assertSame('ok', $redacted['nested']['status']);
        $this->assertSame('[MASQUÉ]', $redacted['Authorization']);
    }

    public function testWritesJsonLinesWithRequestIdAndRespectsMinimumLevel(): void
    {
        $logger = new Logger($this->directory, 'info');
        $logger->setRequestId('req-12345678');
        $logger->debug('ignoré');
        $logger->error('Échec', ['password' => 'x']);

        $files = glob($this->directory . '/app-*.log') ?: [];
        $this->assertCount(1, $files);
        $lines = file($files[0], FILE_IGNORE_NEW_LINES) ?: [];
        $this->assertCount(1, $lines);

        $entry = json_decode($lines[0], true);
        $this->assertSame('error', $entry['level']);
        $this->assertSame('req-12345678', $entry['request_id']);
        $this->assertSame('Échec', $entry['message']);
        $this->assertSame('[MASQUÉ]', $entry['context']['password']);
    }
}
