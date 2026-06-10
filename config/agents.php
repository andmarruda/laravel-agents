<?php

return [
    'default_model' => env('AGENTS_DEFAULT_MODEL', 'openai/gpt-4.1-mini'),

    'capabilities' => [
        'text' => [
            'default_model' => env('AGENTS_DEFAULT_MODEL', 'openai/gpt-4.1-mini'),
        ],

        'image' => [
            'default_model' => env('AGENTS_IMAGE_MODEL', 'openai/gpt-image-1'),
            'storage_disk' => env('AGENTS_IMAGE_DISK', 'public'),
            'default_size' => env('AGENTS_IMAGE_SIZE', '1024x1024'),
        ],
    ],

    'models' => [
        'timeout' => (int) env('AGENTS_MODEL_TIMEOUT', 60),
        'retry_times' => (int) env('AGENTS_MODEL_RETRY_TIMES', 2),
        'retry_sleep' => (int) env('AGENTS_MODEL_RETRY_SLEEP', 250),
    ],

    'providers' => [
        'openai' => [
            'api_key' => env('OPENAI_API_KEY'),
            'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
        ],

        'anthropic' => [
            'api_key' => env('ANTHROPIC_API_KEY'),
            'base_url' => env('ANTHROPIC_BASE_URL', 'https://api.anthropic.com/v1'),
            'version' => env('ANTHROPIC_VERSION', '2023-06-01'),
        ],

        'fireworks' => [
            'api_key' => env('FIREWORKS_API_KEY'),
            'base_url' => env('FIREWORKS_BASE_URL', 'https://api.fireworks.ai/inference/v1'),
        ],
    ],

    'supervisor' => [
        'max_steps' => (int) env('AGENTS_SUPERVISOR_MAX_STEPS', 12),
    ],

    'guardrails' => [
        'max_policies' => (int) env('AGENTS_GUARDRAILS_MAX_POLICIES', 100),

        // Guardrail class names or instances applied to every matching operation.
        'global' => [
            \Andmarruda\LaravelAgents\Guardrails\Policies\ValidateToolArguments::class,
        ],

        'approvals' => [
            'store' => env('AGENTS_APPROVAL_STORE', 'memory'),
            'connection' => env('AGENTS_APPROVAL_DB_CONNECTION'),
            'table' => env('AGENTS_APPROVAL_TABLE', 'agent_approvals'),
        ],

        'redact_keys' => ['password', 'token', 'secret', 'api_key', 'authorization'],
    ],

    'memory' => [
        'short_term' => [
            'connection' => env('AGENTS_REDIS_CONNECTION', 'default'),
            'prefix' => env('AGENTS_MEMORY_PREFIX', 'agents:session:'),
            'default_ttl' => (int) env('AGENTS_MEMORY_TTL', 3600),
        ],

        'long_term' => [
            'connection' => env('AGENTS_DB_CONNECTION'),
            'table' => env('AGENTS_MEMORY_TABLE', 'agent_memories'),
        ],
    ],

    'observability' => [
        'enabled' => env('AGENTS_OBSERVABILITY_ENABLED', false),
        'store' => env('AGENTS_OBSERVABILITY_STORE', 'database'),
        'connection' => env('AGENTS_OBSERVABILITY_DB_CONNECTION'),
        'trace_table' => env('AGENTS_OBSERVABILITY_TRACE_TABLE', 'agent_traces'),
        'span_table' => env('AGENTS_OBSERVABILITY_SPAN_TABLE', 'agent_spans'),

        'dashboard' => [
            'enabled' => env('AGENTS_OBSERVABILITY_DASHBOARD_ENABLED', false),
            'route' => env('AGENTS_OBSERVABILITY_DASHBOARD_ROUTE', '/agents/observability/traces'),
            'middleware' => ['web'],
        ],

        'pricing' => [
            // 'openai/gpt-4.1-mini' => ['input_per_1k' => 0.0004, 'output_per_1k' => 0.0016, 'currency' => 'USD'],
        ],
    ],

    'rag' => [
        'embeddings' => [
            'default_model' => env('AGENTS_RAG_EMBEDDING_MODEL', 'openai/text-embedding-3-small'),
            'dimensions' => env('AGENTS_RAG_EMBEDDING_DIMENSIONS') !== null
                ? (int) env('AGENTS_RAG_EMBEDDING_DIMENSIONS')
                : null,
            'batch_size' => (int) env('AGENTS_RAG_EMBEDDING_BATCH_SIZE', 100),
            'timeout' => (int) env('AGENTS_RAG_EMBEDDING_TIMEOUT', 60),
        ],

        'chunking' => [
            'size' => (int) env('AGENTS_RAG_CHUNK_SIZE', 1000),
            'overlap' => (int) env('AGENTS_RAG_CHUNK_OVERLAP', 150),
        ],

        'vector_store' => [
            'default' => env('AGENTS_RAG_VECTOR_STORE', 'memory'),
            'stores' => [
                'memory' => [],
                'pgvector' => [
                    'connection' => env('AGENTS_RAG_PGVECTOR_CONNECTION'),
                    'table' => env('AGENTS_RAG_PGVECTOR_TABLE', 'agent_rag_vectors'),
                ],
                'qdrant' => [
                    'base_url' => env('QDRANT_URL', 'http://localhost:6333'),
                    'api_key' => env('QDRANT_API_KEY'),
                    'collection' => env('QDRANT_COLLECTION', 'laravel_agents'),
                    'timeout' => (int) env('AGENTS_RAG_QDRANT_TIMEOUT', 60),
                    'auto_create_collection' => env('AGENTS_RAG_QDRANT_AUTO_CREATE_COLLECTION', true),
                ],
            ],
        ],
    ],

    'mcp' => [
        'enabled' => env('AGENTS_MCP_ENABLED', false),

        'server' => [
            'enabled' => env('AGENTS_MCP_SERVER_ENABLED', false),
            'route' => env('AGENTS_MCP_SERVER_ROUTE', '/agents/mcp'),
            'middleware' => ['api'],
            'auth' => null,
            'tools' => [],
            'controllers' => [],
            'routes' => [],
        ],

        'clients' => [
            'servers' => [],
        ],

        'agents' => [],
    ],
];
