<?php
declare(strict_types=1);

namespace App\Core;

final class Router
{
    private array $routes = [];
    private array $middleware = [];

    public function get(string $path, array $handler): void { $this->add('GET', $path, $handler); }
    public function post(string $path, array $handler): void { $this->add('POST', $path, $handler); }
    public function put(string $path, array $handler): void { $this->add('PUT', $path, $handler); }
    public function delete(string $path, array $handler): void { $this->add('DELETE', $path, $handler); }
    public function any(string $path, array $handler): void { $this->add('*', $path, $handler); }

    public function use(callable $fn): void
    {
        $this->middleware[] = $fn;
    }

    private function add(string $method, string $path, array $handler): void
    {
        $pattern = preg_replace('#\{([a-zA-Z_][a-zA-Z0-9_]*)\}#', '(?P<$1>[^/]+)', $path);
        $pattern = '#^' . rtrim($pattern, '/') . '/?$#';
        $this->routes[] = compact('method', 'path', 'pattern', 'handler');
    }

    public function dispatch(): void
    {
        $path = Request::path();
        $method = Request::method();
        foreach ($this->middleware as $fn) $fn($method, $path);
        foreach ($this->routes as $r) {
            if ($r['method'] !== '*' && $r['method'] !== $method) continue;
            if (preg_match($r['pattern'], rtrim($path, '/') ?: '/', $m)) {
                $params = array_filter($m, fn($k) => !is_int($k), ARRAY_FILTER_USE_KEY);
                [$class, $action] = $r['handler'];
                $ctrl = new $class();
                $ctrl->$action($params, $_GET);
                return;
            }
        }
        Response::notFound();
    }
}
