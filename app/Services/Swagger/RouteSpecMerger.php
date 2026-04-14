<?php

namespace App\Services\Swagger;

/**
 * Parses routes/web.php and produces stub OpenAPI operations for every
 * route that isn't already covered by @OA annotations.
 *
 * This guarantees Swagger UI displays a complete list of API endpoints
 * even when individual controllers haven't been annotated yet.
 *
 * Usage:
 *   $merger = new RouteSpecMerger();
 *   $spec   = $merger->mergeRouteStubs($annotationSpec);
 */
class RouteSpecMerger
{
    /** Routes file to scan */
    private string $routesPath;

    /** Public routes that should NOT require Bearer auth */
    private const PUBLIC_PREFIXES = [
        'authentication',
        'login',
        'register',
        'password/',
        'verify_google_otp',
        '2fa/',
        'public/',
        'apply/',
        'merchant/',
        'webhook',
        'twilio/webhook',
        'plivo/webhook',
        'gmail/callback',
        'integrations/google-calendar/callback',
        'stripe/webhook',
        'docs',
        'api/documentation',
        'receiver-fax',
        'redis-test',
        'test-timezone',
    ];

    public function __construct(?string $routesPath = null)
    {
        $this->routesPath = $routesPath ?? base_path('routes/web.php');
    }

    /**
     * Parse routes and merge stub operations into an existing OpenAPI spec.
     *
     * @param  array  $spec  OpenAPI spec as decoded JSON array
     * @return array         Merged spec
     */
    public function mergeRouteStubs(array $spec): array
    {
        if (!isset($spec['paths'])) {
            $spec['paths'] = [];
        }

        // Build a lookup of already-documented operations.
        $documented = [];
        foreach ($spec['paths'] as $path => $methods) {
            foreach ($methods as $method => $op) {
                if (in_array($method, ['get', 'post', 'put', 'patch', 'delete', 'head', 'options'], true)) {
                    $documented[$this->key($method, $path)] = true;
                }
            }
        }

        $routes = $this->parseRoutes();

        // Track tags we add so we can insert them into the global tags list.
        $newTags = [];

        foreach ($routes as $route) {
            $normalisedPath = $this->normalisePath($route['path']);
            $methodLc = strtolower($route['method']);

            if (isset($documented[$this->key($methodLc, $normalisedPath)])) {
                continue;
            }

            $tag = $this->tagFromController($route['controller']);
            $summary = $this->humanise($route['action']);

            if (!isset($spec['paths'][$normalisedPath])) {
                $spec['paths'][$normalisedPath] = [];
            }

            $operation = [
                'tags'        => [$tag],
                'summary'     => $summary,
                'description' => "Auto-generated stub for {$route['controller']}::{$route['action']}. "
                              . "Add a detailed @OA annotation to the controller method to override.",
                'operationId' => $this->operationId($methodLc, $normalisedPath),
                'parameters'  => $this->pathParameters($normalisedPath),
                'responses'   => [
                    '200' => ['description' => 'Successful response'],
                    '400' => ['description' => 'Bad request'],
                    '401' => ['description' => 'Unauthorized (invalid or missing token)'],
                    '403' => ['description' => 'Forbidden (insufficient privileges)'],
                    '500' => ['description' => 'Internal server error'],
                ],
            ];

            // Body parameter for write methods.
            if (in_array($methodLc, ['post', 'put', 'patch'], true)) {
                $operation['requestBody'] = [
                    'description' => 'Request payload',
                    'required'    => false,
                    'content'     => [
                        'application/json' => [
                            'schema' => ['type' => 'object', 'additionalProperties' => true],
                        ],
                        'multipart/form-data' => [
                            'schema' => ['type' => 'object', 'additionalProperties' => true],
                        ],
                    ],
                ];
            }

            if (!$this->isPublic($normalisedPath)) {
                $operation['security'] = [['Bearer' => []]];
            }

            // Preserve any existing tag on the same path (e.g. from other methods).
            $spec['paths'][$normalisedPath][$methodLc] = $operation;
            $newTags[$tag] = true;
        }

        // Merge tags at the top level so Swagger UI groups them.
        if (!isset($spec['tags']) || !is_array($spec['tags'])) {
            $spec['tags'] = [];
        }
        $existingTagNames = array_flip(array_column($spec['tags'], 'name'));
        foreach (array_keys($newTags) as $tagName) {
            if (!isset($existingTagNames[$tagName])) {
                $spec['tags'][] = ['name' => $tagName, 'description' => $tagName . ' endpoints'];
            }
        }

        // Ensure Bearer security scheme is declared.
        if (!isset($spec['components']['securitySchemes']['Bearer'])) {
            $spec['components']['securitySchemes']['Bearer'] = [
                'type'         => 'http',
                'scheme'       => 'bearer',
                'bearerFormat' => 'JWT',
                'description'  => 'JWT issued by POST /authentication. Prefix: "Bearer ".',
            ];
        }

        // Sort paths alphabetically for a stable, navigable UI.
        ksort($spec['paths']);

        return $spec;
    }

    /**
     * Parse $router->METHOD('path', 'Controller@action') calls from web.php.
     *
     * @return array<int, array{method:string,path:string,controller:string,action:string}>
     */
    public function parseRoutes(): array
    {
        $content = file_get_contents($this->routesPath);

        if ($content === false) {
            return [];
        }

        preg_match_all(
            '/\$router\s*->\s*(get|post|put|delete|patch|GET|POST|PUT|DELETE|PATCH)\s*\(\s*[\'"]([^\'"]+)[\'"]\s*,\s*[\'"]([^\'"]+)[\'"]/',
            $content,
            $matches,
            PREG_SET_ORDER
        );

        $routes = [];
        foreach ($matches as $m) {
            $handler = $m[3];
            if (!str_contains($handler, '@')) {
                continue;
            }
            [$controller, $action] = explode('@', $handler, 2);
            $routes[] = [
                'method'     => strtoupper($m[1]),
                'path'       => $m[2],
                'controller' => $controller,
                'action'     => $action,
            ];
        }

        return $routes;
    }

    /** Normalise a route path to OpenAPI form (leading /, strip regex constraints). */
    private function normalisePath(string $path): string
    {
        // Strip "{id:[0-9]+}" → "{id}"
        $path = preg_replace('/\{([a-zA-Z_][a-zA-Z0-9_]*)\s*:[^}]+\}/', '{$1}', $path);

        $path = ltrim($path, '/');
        return '/' . $path;
    }

    /** Build a dedupe key for a method + path pair. */
    private function key(string $method, string $path): string
    {
        return strtolower($method) . ' ' . $path;
    }

    /** Extract OpenAPI parameters from {param} placeholders in a path. */
    private function pathParameters(string $path): array
    {
        preg_match_all('/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/', $path, $m);
        $params = [];
        foreach ($m[1] as $name) {
            $params[] = [
                'name'        => $name,
                'in'          => 'path',
                'required'    => true,
                'description' => 'Path parameter: ' . $name,
                'schema'      => [
                    'type' => ctype_digit(preg_replace('/[^0-9]/', '', $name)) || str_ends_with($name, 'Id') || $name === 'id'
                        ? 'integer'
                        : 'string',
                ],
            ];
        }
        return $params;
    }

    /** Humanise a camelCase method name into a sentence. */
    private function humanise(string $action): string
    {
        // camelCase → "camel Case"
        $spaced = preg_replace('/(?<!^)([A-Z])/', ' $1', $action);
        return ucfirst(strtolower($spaced));
    }

    /** Build a unique operation ID from method + path. */
    private function operationId(string $method, string $path): string
    {
        $id = strtolower($method) . preg_replace('/[^A-Za-z0-9]+/', '_', $path);
        return trim($id, '_');
    }

    /** Infer a tag name from the controller class name. */
    private function tagFromController(string $controller): string
    {
        // Strip namespace separators
        $short = str_replace('\\', '/', $controller);
        $short = basename($short);

        // Drop trailing "Controller"
        if (str_ends_with($short, 'Controller')) {
            $short = substr($short, 0, -10);
        }

        // Split camelCase
        $parts = preg_split('/(?<!^)(?=[A-Z])/', $short);
        return trim(implode(' ', $parts));
    }

    /** Is this path a public endpoint (no Bearer auth required)? */
    private function isPublic(string $path): bool
    {
        $trim = ltrim($path, '/');
        foreach (self::PUBLIC_PREFIXES as $prefix) {
            if (str_starts_with($trim, $prefix)) {
                return true;
            }
        }
        return false;
    }
}
