<?php

namespace Andmarruda\LaravelAgents\Tests\Fakes;

use Andmarruda\LaravelAgents\Contracts\Tool;

class ExampleTool implements Tool
{
    public function name(): string
    {
        return 'example';
    }

    public function description(): string
    {
        return 'Example tool.';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'required' => ['name'],
            'properties' => [
                'name' => ['type' => 'string'],
            ],
        ];
    }

    public function handle(array $input): mixed
    {
        return 'hello '.$input['name'];
    }
}
