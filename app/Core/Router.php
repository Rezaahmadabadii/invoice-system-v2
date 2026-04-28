<?php
namespace App\Core;

class Router
{
    protected $routes = [];
    protected $params = [];

    public function get($route, $controller)
    {
        $this->add('GET', $route, $controller);
    }

    public function post($route, $controller)
    {
        $this->add('POST', $route, $controller);
    }

    public function add($method, $route, $controller)
    {
        $this->routes[] = [
            'method' => $method,
            'route' => $route,
            'controller' => $controller
        ];
    }

    public function dispatch($url)
    {
        $url = $this->getUrl();
        $method = $_SERVER['REQUEST_METHOD'];

        foreach ($this->routes as $route) {
            if ($route['method'] === $method && $this->match($route['route'], $url)) {
                
                if (is_callable($route['controller'])) {
                    call_user_func($route['controller']);
                } else {
                    $this->callController($route['controller']);
                }
                return;
            }
        }

        $this->notFound();
    }

    protected function getUrl()
    {
        $url = isset($_GET['url']) ? $_GET['url'] : '';
        $url = rtrim($url, '/');
        if (empty($url)) {
            $url = '/';
        }
        return $url;
    }

    protected function match($route, $url)
    {
        return ($route === $url);
    }

    protected function callController($controller)
    {
        $parts = explode('@', $controller);
        $controllerName = "App\\Controllers\\{$parts[0]}";
        $methodName = $parts[1];

        if (class_exists($controllerName)) {
            $controller = new $controllerName();
            if (method_exists($controller, $methodName)) {
                call_user_func([$controller, $methodName]);
                return;
            }
        }
        
        $this->notFound();
    }

    protected function notFound()
    {
        http_response_code(404);
        echo "<h1>404 - صفحه یافت نشد</h1>";
        echo "<p>صفحه مورد نظر شما وجود ندارد.</p>";
        exit;
    }
}