<?php

declare(strict_types=1);

namespace Vsh\Core;

/**
 * Conteneur de services minimal : fabriques explicites + construction automatique
 * des classes dont le constructeur ne reçoit que des objets. Tous les services sont uniques (singletons).
 */
final class Container
{
    /** @var array<string,callable> */
    private $factories = [];

    /** @var array<string,mixed> */
    private $instances = [];

    public function set(string $id, callable $factory): void
    {
        $this->factories[$id] = $factory;
        unset($this->instances[$id]);
    }

    /**
     * @param mixed $service
     */
    public function instance(string $id, $service): void
    {
        $this->instances[$id] = $service;
    }

    public function has(string $id): bool
    {
        return array_key_exists($id, $this->instances) || isset($this->factories[$id]) || class_exists($id);
    }

    /**
     * @return mixed
     */
    public function get(string $id)
    {
        if (array_key_exists($id, $this->instances)) {
            return $this->instances[$id];
        }
        $service = isset($this->factories[$id])
            ? call_user_func($this->factories[$id], $this)
            : $this->build($id);
        $this->instances[$id] = $service;
        return $service;
    }

    /**
     * @return object
     */
    private function build(string $class)
    {
        if (!class_exists($class)) {
            throw new \LogicException(sprintf('Service inconnu : %s', $class));
        }
        $reflection = new \ReflectionClass($class);
        if (!$reflection->isInstantiable()) {
            throw new \LogicException(sprintf('La classe %s ne peut pas être instanciée.', $class));
        }
        $constructor = $reflection->getConstructor();
        if ($constructor === null) {
            return new $class();
        }
        $arguments = [];
        foreach ($constructor->getParameters() as $parameter) {
            $type = $parameter->getType();
            if ($type instanceof \ReflectionNamedType && !$type->isBuiltin()) {
                $arguments[] = $this->get($type->getName());
                continue;
            }
            if ($parameter->isDefaultValueAvailable()) {
                $arguments[] = $parameter->getDefaultValue();
                continue;
            }
            throw new \LogicException(sprintf(
                'Impossible de résoudre le paramètre $%s de %s : déclarez une fabrique dans bootstrap/app.php.',
                $parameter->getName(),
                $class
            ));
        }
        return $reflection->newInstanceArgs($arguments);
    }
}
