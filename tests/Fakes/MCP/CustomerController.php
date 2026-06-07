<?php

namespace Andmarruda\LaravelAgents\Tests\Fakes\MCP;

class CustomerController
{
    /**
     * @return array<string, mixed>
     */
    public function show(int $id): array
    {
        return [
            'id' => $id,
            'name' => 'Ada Lovelace',
            'tier' => 'enterprise',
        ];
    }
}
