<?php

namespace Andmarruda\LaravelAgents\Tools;

use InvalidArgumentException;
use Andmarruda\LaravelAgents\Contracts\Tool;

class ToolBag
{
    /**
     * @var array<string, Tool>
     */
    protected array $tools = [];

    /**
     * @param array<int, class-string<Tool>|Tool> $tools
     */
    public function __construct(array $tools = [])
    {
        foreach ($tools as $tool) {
            $this->add($tool);
        }
    }

    /**
     * @param class-string<Tool>|Tool $tool
     */
    public function add(string|Tool $tool): static
    {
        $instance = is_string($tool) ? app($tool) : $tool;

        $this->tools[$instance->name()] = $instance;

        return $this;
    }

    public function get(string $name): Tool
    {
        return $this->tools[$name] ?? throw new InvalidArgumentException("Tool [{$name}] is not registered.");
    }

    public function isEmpty(): bool
    {
        return $this->tools === [];
    }

    public function describe(): string
    {
        return json_encode($this->schemas(), JSON_UNESCAPED_SLASHES) ?: '[]';
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function schemas(): array
    {
        return array_values(array_map(fn (Tool $tool) => [
            'name' => $tool->name(),
            'description' => $tool->description(),
            'schema' => $tool->schema(),
        ], $this->tools));
    }

    /**
     * @return array<string, Tool>
     */
    public function all(): array
    {
        return $this->tools;
    }
}
