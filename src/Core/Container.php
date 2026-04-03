<?php
namespace App\Core;

class Container {
    private array $bindings = [];
    private array $instances = [];

    // Регистрируем способ создания объекта
    public function bind(string $name, callable $resolver): void {
        $this->bindings[$name] = $resolver;
    }

    // Получаем объект (Shared/Singleton внутри контейнера)
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