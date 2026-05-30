<?php

namespace Andmarruda\LaravelAgents\Tests\Fakes;

use Andmarruda\LaravelAgents\Agents\Agent;

class ResearchAgent extends Agent
{
    public function configure(): void
    {
        $this->nameAs('researcher');
        $this->description('Researches facts.');
        $this->instructions('Research carefully.');
        $this->model('worker-model');
    }
}
