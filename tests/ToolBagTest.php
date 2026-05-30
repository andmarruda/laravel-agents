<?php

namespace Andmarruda\LaravelAgents\Tests;

use Andmarruda\LaravelAgents\Tests\Fakes\ExampleTool;
use Andmarruda\LaravelAgents\Tools\ClosureTool;
use Andmarruda\LaravelAgents\Tools\ToolBag;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class ToolBagTest extends TestCase
{
    public function test_it_registers_and_retrieves_tools(): void
    {
        $tool = new ExampleTool();
        $bag = new ToolBag([$tool]);

        $this->assertFalse($bag->isEmpty());
        $this->assertSame($tool, $bag->get('example'));
        $this->assertSame('hello Ana', $bag->get('example')->handle(['name' => 'Ana']));
    }

    public function test_it_describes_tool_schemas(): void
    {
        $bag = new ToolBag([new ExampleTool()]);

        $schemas = $bag->schemas();

        $this->assertSame('example', $schemas[0]['name']);
        $this->assertSame('Example tool.', $schemas[0]['description']);
        $this->assertSame('object', $schemas[0]['schema']['type']);
        $this->assertStringContainsString('"example"', $bag->describe());
    }

    public function test_it_throws_when_tool_is_missing(): void
    {
        $bag = new ToolBag();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Tool [missing] is not registered.');

        $bag->get('missing');
    }

    public function test_closure_tool_executes_handler(): void
    {
        $tool = new ClosureTool(
            name: 'upper',
            description: 'Uppercases text.',
            schema: ['type' => 'object'],
            handler: fn (array $input) => strtoupper($input['text']),
        );

        $this->assertSame('upper', $tool->name());
        $this->assertSame('Uppercases text.', $tool->description());
        $this->assertSame(['type' => 'object'], $tool->schema());
        $this->assertSame('HELLO', $tool->handle(['text' => 'hello']));
    }
}
