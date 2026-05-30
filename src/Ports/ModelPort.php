<?php

namespace Andmarruda\LaravelAgents\Ports;

use Andmarruda\LaravelAgents\Data\ModelResponse;

interface ModelPort extends CapabilityPort
{
    /**
     * @param array<int, array{role: string, content: string}> $messages
     * @param array<string, mixed> $options
     */
    public function generate(array $messages, array $options = []): ModelResponse;
}
