<?php
// backend/src/Router.php

namespace App;

use App\Helpers\Response;

class Router
{
    private array $routes = [];

    public function get(string $path, array $handler, array $middlewares = []): void
    {
        $this->addRoute('GET', $path, $handler, $middlewares);
    }

    public function post(string $path, array $handler, array $middlewares = []): void
    {
        $this->addRoute('POST', $path, $handler, $middlewares);
    }

    public function put(string $path, array $handler, array $middlewares = []): void
    {
        $this->addRoute('PUT', $path, $handler, $middlewares);
    }

    public function delete(string $path, array $handler, array $middlewares = []): void
    {
        $this->addRoute('DELETE', $path, $handler, $middlewares);
    }

    private function addRoute(string $method, string $path, array $handler, array $middlewares): void
    {
        $this->routes[] = [
            'method'      => $method,
            'path'        => $path,
            'handler'     => $handler,
            'middlewares' => $middlewares
        ];
    }

    public function dispatch(): void
    {
        $requestMethod = $_SERVER['REQUEST_METHOD'];
        $requestUri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

        // Normalize path relative to script directory (supports local and production)
        $basePrefixes = [
            '/patrol360/backend/public/index.php',
            '/patrol360/backend/public',
            '/patrol360/backend/index.php',
            '/patrol360/backend',
            '/vehicle_gov/backend/public/index.php',
            '/vehicle_gov/backend/public',
            '/backend/public/index.php',
            '/backend/public',
            '/index.php'
        ];
        foreach ($basePrefixes as $prefix) {
            if (str_starts_with($requestUri, $prefix)) {
                $requestUri = substr($requestUri, strlen($prefix));
                break;
            }
        }

        // Dynamically strip script directory if still present
        $scriptDir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
        if (!empty($scriptDir) && $scriptDir !== '/' && str_starts_with($requestUri, $scriptDir)) {
            $requestUri = substr($requestUri, strlen($scriptDir));
        }

        // Also handle direct domain or trailing slash
        $requestUri = rtrim($requestUri, '/');
        if (empty($requestUri)) {
            $requestUri = '/';
        }

        // Read JSON input body
        $inputData = [];
        $rawBody = file_get_contents('php://input');
        if (!empty($rawBody)) {
            $decoded = json_decode($rawBody, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $inputData = $decoded;
            }
        }
        // Merge with $_POST and $_GET
        $requestBody = array_merge($_POST, $inputData);
        $queryParams = $_GET;

        foreach ($this->routes as $route) {
            if ($route['method'] !== $requestMethod) {
                continue;
            }

            // Convert path like /api/vehicles/:id to regex
            $pattern = preg_replace('/:[a-zA-Z0-9_]+/', '([^/]+)', $route['path']);
            $pattern = '#^' . $pattern . '$#';

            if (preg_match($pattern, $requestUri, $matches)) {
                array_shift($matches); // Remove full match

                // Run middlewares
                $authUserData = null;
                foreach ($route['middlewares'] as $middleware) {
                    if (is_callable($middleware)) {
                        $authUserData = call_user_func($middleware);
                    }
                }

                // Call controller
                [$controllerClass, $methodName] = $route['handler'];
                $controller = new $controllerClass();

                // Pass params, body, query, authUser
                $args = [
                    'params'   => $matches,
                    'body'     => $requestBody,
                    'query'    => $queryParams,
                    'user'     => $authUserData
                ];

                $controller->$methodName($args);
                return;
            }
        }

        Response::error("Endpoint not found: [{$requestMethod}] {$requestUri}", 404);
    }
}
