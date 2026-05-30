<?php

namespace Andmarruda\LaravelAgents\Contracts;

interface Tool
{
    public function name(): string;

    public function description(): string;

    /**
     * @return array<string, mixed>
     */
    public function schema(): array;

    /**
     * @param array<string, mixed> $input
     */
    public function handle(array $input): mixed;
}
