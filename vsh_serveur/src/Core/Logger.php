<?php

declare(strict_types=1);

namespace Vsh\Core;

/**
 * Journal technique au format JSON (une ligne par événement), un fichier par jour dans storage/logs.
 * Les clés sensibles (mots de passe, jetons, codes OTP, secrets) sont masquées avant écriture.
 */
final class Logger
{
    private const LEVELS = [
        'debug' => 100,
        'info' => 200,
        'warning' => 300,
        'error' => 400,
        'critical' => 500,
    ];

    private const SENSITIVE_KEYS = [
        'authorization',
        'api_key',
        'app_key',
        'code_hash',
        'otp',
        'otp_code',
    ];

    private const SENSITIVE_FRAGMENTS = ['password', 'secret', 'token'];

    /** @var string */
    private $directory;

    /** @var int */
    private $minLevel;

    /** @var string|null */
    private $requestId;

    public function __construct(string $directory, string $minLevel = 'info')
    {
        $this->directory = rtrim($directory, '/\\');
        $this->minLevel = self::LEVELS[strtolower($minLevel)] ?? self::LEVELS['info'];
    }

    public function setRequestId(?string $requestId): void
    {
        $this->requestId = $requestId;
    }

    public function debug(string $message, array $context = []): void
    {
        $this->log('debug', $message, $context);
    }

    public function info(string $message, array $context = []): void
    {
        $this->log('info', $message, $context);
    }

    public function warning(string $message, array $context = []): void
    {
        $this->log('warning', $message, $context);
    }

    public function error(string $message, array $context = []): void
    {
        $this->log('error', $message, $context);
    }

    public function critical(string $message, array $context = []): void
    {
        $this->log('critical', $message, $context);
    }

    public function log(string $level, string $message, array $context = []): void
    {
        $weight = self::LEVELS[$level] ?? self::LEVELS['error'];
        if ($weight < $this->minLevel) {
            return;
        }
        $entry = [
            'time' => gmdate('Y-m-d\TH:i:s\Z'),
            'level' => $level,
            'request_id' => $this->requestId,
            'message' => $message,
        ];
        if ($context !== []) {
            $entry['context'] = self::redact($context);
        }
        $line = (string) json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);

        if (!is_dir($this->directory)) {
            @mkdir($this->directory, 0750, true);
        }
        $file = $this->directory . '/app-' . gmdate('Y-m-d') . '.log';
        if (@file_put_contents($file, $line . PHP_EOL, FILE_APPEND | LOCK_EX) === false) {
            error_log('[vsh] ' . $line);
        }
    }

    public static function redact(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_string($key) && self::isSensitive($key)) {
                $data[$key] = '[MASQUÉ]';
            } elseif (is_array($value)) {
                $data[$key] = self::redact($value);
            }
        }
        return $data;
    }

    private static function isSensitive(string $key): bool
    {
        $key = strtolower($key);
        if (in_array($key, self::SENSITIVE_KEYS, true)) {
            return true;
        }
        foreach (self::SENSITIVE_FRAGMENTS as $fragment) {
            if (strpos($key, $fragment) !== false) {
                return true;
            }
        }
        return false;
    }
}
