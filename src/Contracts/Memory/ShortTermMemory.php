<?php

namespace Andmarruda\LaravelAgents\Contracts\Memory;

interface ShortTermMemory
{
    /**
     * @param array{role: string, content: string} $message
     */
    public function append(string $sessionId, array $message, int $ttl): void;

    /**
     * @return array<int, array{role: string, content: string}>
     */
    public function messages(string $sessionId): array;

    public function clear(string $sessionId): void;
}
