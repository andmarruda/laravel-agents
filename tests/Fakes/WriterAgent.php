<?php

namespace Andmarruda\LaravelAgents\Tests\Fakes;

use Andmarruda\LaravelAgents\Agents\Agent;

class WriterAgent extends Agent
{
    public function configure(): void
    {
        $this->nameAs('writer');
        $this->description('Writes concise content.');
        $this->instructions('Write clearly.');
        $this->model('worker-model');
    }
}
