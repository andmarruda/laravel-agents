<?php

namespace Andmarruda\LaravelAgents\Tests\Fakes;

use Andmarruda\LaravelAgents\Agents\SupervisorAgent;

class ManagerAgent extends SupervisorAgent
{
    public function configure(): void
    {
        $this->nameAs('manager');
        $this->description('Coordinates workers.');
        $this->instructions('Coordinate workers until the task is complete.');
        $this->model('supervisor-model');
        $this->maxSteps(3);
        $this->withAgents([
            new ResearchAgent(),
            new WriterAgent(),
        ]);
    }
}
