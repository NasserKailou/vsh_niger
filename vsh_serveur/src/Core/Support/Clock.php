<?php

declare(strict_types=1);

namespace Vsh\Core\Support;

/**
 * Horloge de l'application, toujours en UTC. Remplaçable dans les tests.
 */
final class Clock
{
    /** @var \DateTimeImmutable|null */
    private static $testNow;

    public static function now(): \DateTimeImmutable
    {
        return self::$testNow ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    /**
     * Format des colonnes DATETIME.
     */
    public static function nowForDatabase(): string
    {
        return self::now()->format('Y-m-d H:i:s');
    }

    /**
     * Conversion d'une valeur DATETIME (UTC) vers le format ISO 8601 des réponses de l'API.
     */
    public static function toIso(?string $databaseValue): ?string
    {
        if ($databaseValue === null || $databaseValue === '') {
            return null;
        }
        return (new \DateTimeImmutable($databaseValue, new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
    }

    public static function setTestNow(?\DateTimeImmutable $now): void
    {
        self::$testNow = $now === null ? null : $now->setTimezone(new \DateTimeZone('UTC'));
    }
}
