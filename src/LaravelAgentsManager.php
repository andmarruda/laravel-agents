<?php

namespace Andmarruda\LaravelAgents;

use Andmarruda\LaravelAgents\Agents\Agent;
use Andmarruda\LaravelAgents\Images\ImageRouter;
use Andmarruda\LaravelAgents\Kernel\AgentKernel;
use Andmarruda\LaravelAgents\MCP\Server\McpToolRegistry;
use Andmarruda\LaravelAgents\Models\ModelRouter;
use Andmarruda\LaravelAgents\Observability\TraceManager;
use Andmarruda\LaravelAgents\Ports\ImageGenerationPort;
use Andmarruda\LaravelAgents\Ports\ModelPort;
use Andmarruda\LaravelAgents\Workflows\Workflow;

class LaravelAgentsManager
{
    /**
     * Create the package manager with the configured routers and kernel.
     *
     * @param ModelRouter $models Router used to resolve text model providers.
     * @param ImageRouter $images Router used to resolve image generation providers.
     * @param AgentKernel $kernel Capability kernel exposed to applications.
     * @return void
     */
    public function __construct(
        protected ModelRouter $models,
        protected ImageRouter $images,
        protected AgentKernel $kernel,
        protected ?McpToolRegistry $mcpToolRegistry = null,
        protected ?TraceManager $traceManager = null,
    ) {
    }

    /**
     * Return the configured capability kernel.
     *
     * @return AgentKernel
     */
    public function kernel(): AgentKernel
    {
        return $this->kernel;
    }

    public function mcp(): ?McpToolRegistry
    {
        return $this->mcpToolRegistry;
    }

    /**
     * Resolve a text model provider by provider/model name.
     *
     * @param string $model Provider/model name, such as openai/gpt-4.1-mini.
     * @return ModelPort
     */
    public function model(string $model): ModelPort
    {
        return $this->models->for($model);
    }

    /**
     * Resolve an image generation provider by model name.
     *
     * @param string|null $model Optional provider/model name, or null to use the configured default.
     * @return ImageGenerationPort
     */
    public function image(?string $model = null): ImageGenerationPort
    {
        return $this->images->for($model);
    }

    /**
     * Resolve and boot an agent instance with the configured model router.
     *
     * @param class-string<Agent>|Agent $agent
     *        Agent class name or existing agent instance.
     * @return Agent
     */
    public function agent(string|Agent $agent): Agent
    {
        $instance = is_string($agent) ? app($agent) : $agent;

        $instance->setModelRouter($this->models);

        if ($this->mcpToolRegistry) {
            $instance->setMcpToolRegistry($this->mcpToolRegistry);
        }

        if ($this->traceManager) {
            $instance->setTraceManager($this->traceManager);
        }

        $instance->bootAgent();

        return $instance;
    }

    /**
     * Resolve a workflow instance or create a new fluent workflow builder.
     *
     * @param class-string<Workflow>|Workflow|null $workflow
     *        Optional workflow class name or instance to resolve.
     * @return Workflow
     */
    public function workflow(string|Workflow|null $workflow = null): Workflow
    {
        if ($workflow === null) {
            return Workflow::make()->setTraceManager($this->traceManager);
        }

        $instance = is_string($workflow) ? app($workflow) : $workflow;

        return $instance->setTraceManager($this->traceManager);
    }
}
