<?php

declare(strict_types=1);

namespace Vsh\Modules\Sync;

/**
 * Entités synchronisables. Chaque module déclare les siennes dans src/Modules/<Module>/sync.php.
 */
final class SyncRegistry
{
    /** @var array<string,SyncEntityHandler> */
    private $handlers = [];

    public function register(SyncEntityHandler $handler): void
    {
        $this->handlers[$handler->entity()] = $handler;
    }

    public function get(string $entity): ?SyncEntityHandler
    {
        return $this->handlers[$entity] ?? null;
    }

    /**
     * @return SyncEntityHandler[]
     */
    public function all(): array
    {
        return array_values($this->handlers);
    }

    /**
     * @return string[]
     */
    public function entities(): array
    {
        return array_keys($this->handlers);
    }
}
