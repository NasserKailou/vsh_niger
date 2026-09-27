<?php

declare(strict_types=1);

namespace Vsh\Core;

/**
 * Configuration applicative : un fichier config/<nom>.php par section, lu par clé pointée ("app.debug").
 */
final class Config
{
    /** @var array<string,mixed> */
    private $items;

    public function __construct(array $items)
    {
        $this->items = $items;
    }

    public static function fromDirectory(string $directory): self
    {
        $items = [];
        $files = glob(rtrim($directory, '/\\') . '/*.php');
        foreach ($files ?: [] as $file) {
            $items[basename($file, '.php')] = require $file;
        }
        return new self($items);
    }

    /**
     * @param mixed $default
     * @return mixed
     */
    public function get(string $key, $default = null)
    {
        $value = $this->items;
        foreach (explode('.', $key) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }
        return $value;
    }

    /**
     * @param mixed $value
     */
    public function set(string $key, $value): void
    {
        $segments = explode('.', $key);
        $node = &$this->items;
        foreach ($segments as $segment) {
            if (!isset($node[$segment]) || !is_array($node[$segment])) {
                $node[$segment] = [];
            }
            $node = &$node[$segment];
        }
        $node = $value;
    }
}
