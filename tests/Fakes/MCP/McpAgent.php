<?php

namespace Andmarruda\LaravelAgents\Tests\Fakes\MCP;

use Andmarruda\LaravelAgents\Agents\Agent;

class McpAgent extends Agent
{
    public function configure(): void
    {
        $this->nameAs('mcp-agent');
        $this->model('mcp-model');
        $this->withMcpServers(['crm']);
        $this->allowMcpTools(['crm.customers.find']);
    }
}
