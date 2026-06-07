<?php

namespace Andmarruda\LaravelAgents\MCP\Tools;

use Andmarruda\LaravelAgents\Contracts\Tool;
use Andmarruda\LaravelAgents\MCP\Schema\ToolSchemaConverter;
use ReflectionMethod;
use ReflectionNamedType;

class ControllerTool implements Tool
{
    use NormalizesToolResponses;

    /**
     * @param class-string|object $controller
     * @param array<string, mixed> $schema
     * @param callable(class-string|object): object|null $resolver
     */
    public function __construct(
        protected string $name,
        protected string $description,
        protected string|object $controller,
        protected string $method,
        protected array $schema,
        protected mixed $resolver = null,
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
        $controller = $this->resolveController();
        $method = new ReflectionMethod($controller, $this->method);
        $arguments = $this->resolveArguments($method, $input);

        return $this->normalizeResponse($method->invokeArgs($controller, $arguments));
    }

    protected function resolveController(): object
    {
        if (is_object($this->controller)) {
            return $this->controller;
        }

        if (is_callable($this->resolver)) {
            return ($this->resolver)($this->controller);
        }

        if (function_exists('app')) {
            return app($this->controller);
        }

        return new $this->controller();
    }

    /**
     * @return array<int, mixed>
     */
    protected function resolveArguments(ReflectionMethod $method, array $input): array
    {
        $parameters = $method->getParameters();

        if (count($parameters) === 1) {
            $parameter = $parameters[0];
            $type = $parameter->getType();

            if ($type instanceof ReflectionNamedType) {
                if ($type->getName() === 'array') {
                    return [$input];
                }

                if (class_exists($type->getName()) && is_a($type->getName(), 'Illuminate\Http\Request', true)) {
                    return [$this->makeRequest($input)];
                }
            }
        }

        $arguments = [];

        foreach ($parameters as $parameter) {
            $name = $parameter->getName();

            if (array_key_exists($name, $input)) {
                $arguments[] = $input[$name];
                continue;
            }

            if ($parameter->isDefaultValueAvailable()) {
                $arguments[] = $parameter->getDefaultValue();
                continue;
            }

            $arguments[] = null;
        }

        return $arguments;
    }

    protected function makeRequest(array $input): mixed
    {
        if (class_exists('Illuminate\Http\Request')) {
            return \Illuminate\Http\Request::create('/', 'POST', $input);
        }

        return $input;
    }

}
