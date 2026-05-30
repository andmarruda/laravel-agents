<?php

namespace Andmarruda\LaravelAgents\Tools;

use Closure;
use Andmarruda\LaravelAgents\Contracts\Tool;

class ClosureTool implements Tool
{
    /**
     * @param array<string, mixed> $schema
     */
    public function __construct(
        protected string $name,
        protected string $description,
        protected array $schema,
        protected Closure $handler,
    ) {
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
        return $this->schema;
    }

    public function handle(array $input): mixed
    {
        return ($this->handler)($input);
    }
}
