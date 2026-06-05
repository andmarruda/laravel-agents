<?php

namespace Andmarruda\LaravelAgents\Workflows;

class WorkflowContext
{
    /**
     * Create a workflow context with initial shared values.
     *
     * @param array<string, mixed> $values
     *        Initial context values available to workflow steps.
     * @return void
     */
    public function __construct(
        protected array $values = [],
    ) {
    }

    /**
     * Retrieve a value from the workflow context.
     *
     * @param string $key Context key to read.
     * @param mixed $default Value returned when the key is not present.
     * @return mixed Stored value or the provided default.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return $this->values[$key] ?? $default;
    }

    /**
     * Store a value in the workflow context.
     *
     * @param string $key Context key to write.
     * @param mixed $value Value to store.
     * @return static
     */
    public function put(string $key, mixed $value): static
    {
        $this->values[$key] = $value;

        return $this;
    }

    /**
     * Merge multiple values into the workflow context.
     *
     * @param array<string, mixed> $values
     *        Values merged over the current context.
     * @return static
     */
    public function merge(array $values): static
    {
        $this->values = [
            ...$this->values,
            ...$values,
        ];

        return $this;
    }

    /**
     * Return all values currently stored in the workflow context.
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->values;
    }
}
