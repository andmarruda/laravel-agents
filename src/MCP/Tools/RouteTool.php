<?php

namespace Andmarruda\LaravelAgents\MCP\Tools;

use Andmarruda\LaravelAgents\Contracts\Tool;
use Andmarruda\LaravelAgents\MCP\Schema\ToolSchemaConverter;
use RuntimeException;

class RouteTool implements Tool
{
    use NormalizesToolResponses;

    /**
     * @param array<string, mixed> $schema
     * @param array<string, array<int, string>> $map
     * @param callable(string, string, array<string, mixed>, array<string, mixed>): mixed|null $dispatcher
     */
    public function __construct(
        protected string $name,
        protected string $description,
        protected string $route,
        protected string $method,
        protected array $schema,
        protected array $map = [],
        protected mixed $dispatcher = null,
        protected ?ToolSchemaConverter $schemaConverter = null,
    ) {
        $this->schemaConverter ??= new ToolSchemaConverter();
    }

    public function name(): string
    {
        return $this->name;
    }

    public function description(): string
    {
        return $this->description;
    }

    public function schema(): array
    {
        return $this->schemaConverter->toMcpInputSchema($this->schema);
    }

    public function handle(array $input): mixed
    {
        [$routeParameters, $body] = $this->mapInput($input);

        if (is_callable($this->dispatcher)) {
            return $this->normalizeResponse(($this->dispatcher)($this->route, $this->method, $routeParameters, $body));
        }

        if (! function_exists('app')) {
            throw new RuntimeException('RouteTool requires a Laravel application or a custom dispatcher.');
        }

        $router = app('router');
        $url = method_exists($router, 'getRoutes') && function_exists('route')
            ? route($this->route, $routeParameters, false)
            : $this->route;

        $request = \Illuminate\Http\Request::create($url, $this->method, $body);

        return $this->normalizeResponse($router->dispatch($request));
    }

    /**
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    protected function mapInput(array $input): array
    {
        $routeParameters = [];
        $body = [];

        foreach ($this->map['route'] ?? [] as $key) {
            if (array_key_exists($key, $input)) {
                $routeParameters[$key] = $input[$key];
            }
        }

        foreach ($this->map['body'] ?? [] as $key) {
            if (array_key_exists($key, $input)) {
                $body[$key] = $input[$key];
            }
        }

        if ($body === [] && ($this->map['body'] ?? []) === []) {
            $body = array_diff_key($input, $routeParameters);
        }

        return [$routeParameters, $body];
    }
}
