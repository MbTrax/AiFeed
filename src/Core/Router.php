<?php
namespace App\Core;

class Router {
    private array $routes = [];
    private Container $container;

    public function __construct(Container $container) {
        $this->container = $container;
    }

    public function add(string $uri, string $controller, string $method): void {
        $this->routes[$uri] = ['controller' => $controller, 'method' => $method];
    }

    public function dispatch(string $uri) {
        if (!isset($this->routes[$uri])) {
            header("HTTP/1.0 404 Not Found");
            return "404 Not Found";
        }

        $route = $this->routes[$uri];
        $controllerName = $route['controller'];
        $methodName = $route['method'];

        $reflection = new \ReflectionClass($controllerName);
        $constructor = $reflection->getConstructor();

        $dependencies = [];
        if ($constructor) {
            foreach ($constructor->getParameters() as $param) {
                $dependencies[] = $this->container->make($param->getName());
            }
        }

        // Создаем контроллер, передавая ему зависимости автоматически
        $controller = $reflection->newInstanceArgs($dependencies);

        return $controller->$methodName();
    }
}