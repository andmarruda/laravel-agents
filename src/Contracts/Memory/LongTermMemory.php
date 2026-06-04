<?php

namespace Andmarruda\LaravelAgents\Contracts\Memory;

interface LongTermMemory
{
    public function store(string $agent, string $scope, string $key, mixed $value): void;

    /**
     * @return array<string, mixed>
     */
    public function retrieve(string $agent, string $scope, ?string $key = null): array;

    public function forget(string $agent, string $scope, ?string $key = null): void;
}
