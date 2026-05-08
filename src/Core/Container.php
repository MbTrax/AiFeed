<?php
namespace App\Core;

class Container {
    private array $bindings = [];
    private array $instances = [];

    public function bind(string $name, callable $resolver): void {
        $this->bindings[$name] = $resolver;
    }

    public function make(string $name) {
        if (!isset($this->instances[$name])) {
            if (!isset($this->bindings[$name])) {
                throw new \Exception("Сервис {$name} не зарегистрирован.");
            }
            $this->instances[$name] = $this->bindings[$name]($this);
        }
        return $this->instances[$name];
    }
}