<?php

namespace Andmarruda\LaravelAgents\MCP\Auth;

use Andmarruda\LaravelAgents\MCP\Data\McpToolDefinition;

interface AuthorizesMcpRequests
{
    public function authorize(mixed $request, ?McpToolDefinition $tool = null): McpAuthenticationResult;
}
