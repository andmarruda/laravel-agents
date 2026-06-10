<?php

namespace Andmarruda\LaravelAgents;

use Andmarruda\LaravelAgents\Contracts\Memory\LongTermMemory;
use Andmarruda\LaravelAgents\Contracts\Memory\ShortTermMemory;
use Andmarruda\LaravelAgents\Images\ImageRouter;
use Andmarruda\LaravelAgents\Guardrails\Contracts\ApprovalStore;
use Andmarruda\LaravelAgents\Guardrails\GuardrailPipeline;
use Andmarruda\LaravelAgents\Guardrails\Stores\DatabaseApprovalStore;
use Andmarruda\LaravelAgents\Guardrails\Stores\InMemoryApprovalStore;
use Andmarruda\LaravelAgents\Kernel\AgentKernel;
use Andmarruda\LaravelAgents\MCP\Auth\AuthorizesMcpRequests;
use Andmarruda\LaravelAgents\MCP\Schema\JsonSchemaValidator;
use Andmarruda\LaravelAgents\MCP\Schema\ToolSchemaConverter;
use Andmarruda\LaravelAgents\MCP\Server\HttpMcpController;
use Andmarruda\LaravelAgents\MCP\Server\McpRequestHandler;
use Andmarruda\LaravelAgents\MCP\Server\McpServer;
use Andmarruda\LaravelAgents\MCP\Server\McpToolRegistry;
use Andmarruda\LaravelAgents\Memory\DatabaseLongTermAdapter;
use Andmarruda\LaravelAgents\Memory\RedisShortTermAdapter;
use Andmarruda\LaravelAgents\Models\ModelRouter;
use Andmarruda\LaravelAgents\Observability\Contracts\TraceExporter;
use Andmarruda\LaravelAgents\Observability\Contracts\TraceStore;
use Andmarruda\LaravelAgents\Observability\Exporters\NullTraceExporter;
use Andmarruda\LaravelAgents\Observability\Http\TraceDashboardController;
use Andmarruda\LaravelAgents\Observability\Stores\DatabaseTraceStore;
use Andmarruda\LaravelAgents\Observability\Stores\NullTraceStore;
use Andmarruda\LaravelAgents\Observability\Support\CostCalculator;
use Andmarruda\LaravelAgents\Observability\Support\LaravelEventDispatcher;
use Andmarruda\LaravelAgents\Observability\TraceManager;
use Andmarruda\LaravelAgents\RAG\Chunking\RecursiveCharacterChunker;
use Andmarruda\LaravelAgents\RAG\Contracts\Chunker;
use Andmarruda\LaravelAgents\RAG\Contracts\EmbeddingProvider;
use Andmarruda\LaravelAgents\RAG\Contracts\VectorStore;
use Andmarruda\LaravelAgents\RAG\Embeddings\EmbeddingRouter;
use Andmarruda\LaravelAgents\RAG\RagIndexer;
use Andmarruda\LaravelAgents\RAG\Retriever;
use Andmarruda\LaravelAgents\RAG\VectorStores\InMemoryVectorStore;
use Andmarruda\LaravelAgents\RAG\VectorStores\VectorStoreRouter;
use Illuminate\Support\ServiceProvider;

class LaravelAgentsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/agents.php', 'agents');

        $this->app->singleton(ModelRouter::class, function ($app) {
            return new ModelRouter(
                $app['config']->get('agents', []),
                $app->make(TraceManager::class),
            );
        });

        $this->app->singleton(ImageRouter::class, function ($app) {
            return new ImageRouter($app['config']->get('agents', []));
        });

        $this->app->singleton(AgentKernel::class, function ($app) {
            return new AgentKernel(
                $app->make(ModelRouter::class),
                $app->make(ImageRouter::class),
            );
        });

        $this->app->singleton(ToolSchemaConverter::class, fn () => new ToolSchemaConverter());
        $this->app->singleton(JsonSchemaValidator::class, fn () => new JsonSchemaValidator());

        $this->app->singleton(McpToolRegistry::class, function ($app) {
            return new McpToolRegistry(
                $app['config']->get('agents.mcp', []),
                schemaConverter: $app->make(ToolSchemaConverter::class),
            );
        });

        $this->app->singleton(McpServer::class, function ($app) {
            $authClass = $app['config']->get('agents.mcp.server.auth');
            $authorizer = $authClass ? $app->make($authClass) : null;

            return new McpServer(
                $app->make(McpToolRegistry::class),
                $authorizer instanceof AuthorizesMcpRequests ? $authorizer : null,
                $app->make(ToolSchemaConverter::class),
                $app->make(JsonSchemaValidator::class),
                guardrailPipeline: $app->make(GuardrailPipeline::class),
            );
        });

        $this->app->singleton(McpRequestHandler::class, function ($app) {
            return new McpRequestHandler($app->make(McpServer::class));
        });

        $this->app->singleton(HttpMcpController::class, function ($app) {
            return new HttpMcpController($app->make(McpRequestHandler::class));
        });

        $this->app->singleton(CostCalculator::class, function ($app) {
            return new CostCalculator($app['config']->get('agents.observability.pricing', []));
        });

        $this->app->singleton(TraceExporter::class, fn () => new NullTraceExporter());

        $this->app->singleton(TraceStore::class, function ($app) {
            if (! $app['config']->get('agents.observability.enabled', false)) {
                return new NullTraceStore();
            }

            if ($app['config']->get('agents.observability.store', 'database') !== 'database') {
                return new NullTraceStore();
            }

            return new DatabaseTraceStore(
                $app['db']->connection($app['config']->get('agents.observability.connection')),
                $app['config']->get('agents.observability.trace_table', 'agent_traces'),
                $app['config']->get('agents.observability.span_table', 'agent_spans'),
            );
        });

        $this->app->singleton(TraceManager::class, function ($app) {
            return new TraceManager(
                $app->make(TraceStore::class),
                $app->make(TraceExporter::class),
                $app->make(CostCalculator::class),
                new LaravelEventDispatcher(),
                (bool) $app['config']->get('agents.observability.enabled', false),
                $app['config']->get('agents.guardrails.redact_keys', []),
            );
        });

        $this->app->singleton(TraceDashboardController::class, function ($app) {
            return new TraceDashboardController($app->make(TraceStore::class));
        });

        $this->app->singleton(ApprovalStore::class, function ($app) {
            if ($app['config']->get('agents.guardrails.approvals.store', 'memory') === 'database') {
                return new DatabaseApprovalStore(
                    $app['db']->connection($app['config']->get('agents.guardrails.approvals.connection')),
                    $app['config']->get('agents.guardrails.approvals.table', 'agent_approvals'),
                    new LaravelEventDispatcher(),
                );
            }

            return new InMemoryApprovalStore(new LaravelEventDispatcher());
        });

        $this->app->singleton(GuardrailPipeline::class, function ($app) {
            $guardrails = [];
            foreach ($app['config']->get('agents.guardrails.global', []) as $guardrail) {
                $guardrails[] = is_string($guardrail) ? $app->make($guardrail) : $guardrail;
            }

            return new GuardrailPipeline(
                $guardrails,
                (int) $app['config']->get('agents.guardrails.max_policies', 100),
                $app->make(ApprovalStore::class),
                $app->make(TraceManager::class),
            );
        });

        $this->app->singleton(EmbeddingRouter::class, function ($app) {
            return new EmbeddingRouter(
                $app['config']->get('agents', []),
                $app->make(\Illuminate\Http\Client\Factory::class),
            );
        });

        $this->app->singleton(InMemoryVectorStore::class, fn () => new InMemoryVectorStore());
        $this->app->singleton(VectorStoreRouter::class, function ($app) {
            return new VectorStoreRouter($app['config']->get('agents', []), $app);
        });

        $this->app->singleton(Chunker::class, function ($app) {
            return new RecursiveCharacterChunker(
                (int) $app['config']->get('agents.rag.chunking.size', 1000),
                (int) $app['config']->get('agents.rag.chunking.overlap', 150),
            );
        });

        $this->app->bind(EmbeddingProvider::class, fn ($app) => $app->make(EmbeddingRouter::class)->for());
        $this->app->bind(VectorStore::class, fn ($app) => $app->make(VectorStoreRouter::class)->for());
        $this->app->bind(RagIndexer::class, fn ($app) => new RagIndexer(
            $app->make(Chunker::class),
            $app->make(EmbeddingProvider::class),
            $app->make(VectorStore::class),
        ));
        $this->app->bind(Retriever::class, fn ($app) => new Retriever(
            $app->make(EmbeddingProvider::class),
            $app->make(VectorStore::class),
        ));

        $this->app->singleton(LaravelAgentsManager::class, function ($app) {
            return new LaravelAgentsManager(
                $app->make(ModelRouter::class),
                $app->make(ImageRouter::class),
                $app->make(AgentKernel::class),
                $app->make(McpToolRegistry::class),
                $app->make(TraceManager::class),
                $app->make(EmbeddingRouter::class),
                $app->make(VectorStoreRouter::class),
                $app->make(Chunker::class),
                $app->make(GuardrailPipeline::class),
                $app->make(ApprovalStore::class),
            );
        });

        $this->app->singleton(ShortTermMemory::class, function ($app) {
            $connection = $app['redis']->connection(
                $app['config']->get('agents.memory.short_term.connection', 'default')
            );

            return new RedisShortTermAdapter(
                $connection,
                $app['config']->get('agents.memory.short_term.prefix', 'agents:session:'),
            );
        });

        $this->app->singleton(LongTermMemory::class, function ($app) {
            return new DatabaseLongTermAdapter(
                $app['db']->connection(
                    $app['config']->get('agents.memory.long_term.connection')
                ),
                $app['config']->get('agents.memory.long_term.table', 'agent_memories'),
            );
        });
    }

    public function boot(): void
    {
        if (
            $this->app['config']->get('agents.mcp.server.enabled', false)
            && $this->app->bound('router')
        ) {
            $this->app['router']
                ->middleware($this->app['config']->get('agents.mcp.server.middleware', ['api']))
                ->post(
                    $this->app['config']->get('agents.mcp.server.route', '/agents/mcp'),
                    HttpMcpController::class
                );
        }

        if (
            $this->app['config']->get('agents.observability.dashboard.enabled', false)
            && $this->app->bound('router')
        ) {
            $route = $this->app['config']->get('agents.observability.dashboard.route', '/agents/observability/traces');
            $this->app['router']
                ->middleware($this->app['config']->get('agents.observability.dashboard.middleware', ['web']))
                ->get($route, [TraceDashboardController::class, 'index']);
            $this->app['router']
                ->middleware($this->app['config']->get('agents.observability.dashboard.middleware', ['web']))
                ->get($route.'/{traceId}', [TraceDashboardController::class, 'show']);
        }

        $this->publishes([
            __DIR__.'/../config/agents.php' => config_path('agents.php'),
        ], 'agents-config');

        $this->publishes([
            __DIR__.'/../database/migrations/create_agent_memories_table.php' => database_path(
                'migrations/'.date('Y_m_d_His').'_create_agent_memories_table.php'
            ),
        ], 'agents-migrations');

        $this->publishes([
            __DIR__.'/../database/migrations/create_agent_observability_tables.php' => database_path(
                'migrations/'.date('Y_m_d_His', time() + 1).'_create_agent_observability_tables.php'
            ),
        ], 'agents-migrations');

        $this->publishes([
            __DIR__.'/../database/migrations/create_agent_rag_pgvector_table.php' => database_path(
                'migrations/'.date('Y_m_d_His', time() + 2).'_create_agent_rag_pgvector_table.php'
            ),
        ], 'agents-rag-pgvector-migrations');

        $this->publishes([
            __DIR__.'/../database/migrations/create_agent_approvals_table.php' => database_path(
                'migrations/'.date('Y_m_d_His', time() + 3).'_create_agent_approvals_table.php'
            ),
        ], 'agents-migrations');
    }
}
