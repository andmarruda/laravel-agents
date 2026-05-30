<?php

namespace Andmarruda\LaravelAgents\Tests\Fakes;

use Andmarruda\LaravelAgents\Agents\Agent;

class ToolCallingAgent extends Agent
{
    public function configure(): void
    {
        $this->nameAs('tool_caller');
        $this->description('Calls tools.');
        $this->instructions('Call tools when useful.');
        $this->model('tool-model');
        $this->withTools([
            new ExampleTool(),
        ]);
    }
}
