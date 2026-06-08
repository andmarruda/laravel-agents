# Laravel Agents Roadmap

## v0.1 Core Orchestration

Goal: make manager/worker agent orchestration usable in Laravel apps.

- Package skeleton with Laravel auto-discovery.
- Model router using `provider/model` names.
- `ModelPort` plus adapters for OpenAI, Anthropic/Claude, and Fireworks.
- `ImageGenerationPort` plus OpenAI image adapter.
- `AgentKernel` for capability routing.
- Base `Agent` class.
- `SupervisorAgent` that delegates to workers or returns a final answer.
- Tool contract, `ToolBag`, and JSON-based tool execution loop.
- Basic run metadata in `AgentResponse`.

## v0.2 Memory

Goal: persistent context and thread-aware agents.

- Migrations for `agent_threads`, `agent_messages`, `agent_runs`, `agent_steps`, and `agent_tool_calls`.
- `resource_id` and `thread_id` support.
- SQL message history.
- Working memory stored as JSON.
- Memory isolation for supervisor and workers.
- Configurable pruning and summarization.

## v0.3 Workflows

Goal: deterministic process orchestration when agentic routing is too open-ended.

- `Workflow`, `Step`, `WorkflowContext`, and `WorkflowResponse`.
- `then`, `branch`, `parallel`, `loopUntil`, and `forEach`.
- Synchronous workflow execution with ordered step history.
- Input/output schemas for workflow payloads.
- Laravel Queue-friendly workflow jobs.
- Suspend/resume snapshots.
- Human approval steps.
- Persistent workflow store adapters.

## v0.4 MCP

Goal: expose and consume external tools through Model Context Protocol.

- MCP client for remote servers.
- MCP server for Laravel tools.
- Tool discovery and schema conversion.
- Authentication hooks.
- Per-agent allowed MCP servers.

## 0.5 Observability

Goal: make agent runs debuggable in production.

- Trace and span model.
- Token usage, latency, provider, model, and cost metadata.
- Laravel events for agent, model, tool, and workflow lifecycle.
- Optional dashboard routes.
- Export adapters for OpenTelemetry-style traces.

## v0.6 RAG

Goal: retrieval-augmented agents with Laravel-friendly storage.

- Document loaders.
- Chunking and metadata normalization.
- Embedding provider abstraction.
- Vector store contracts.
- First adapters for PostgreSQL/pgvector and external stores.
- Retriever tools usable by agents and workflows.

## v0.7 Guardrails

Goal: policy, validation, and safety around model calls and tool execution.

- Input guardrails before model calls.
- Output guardrails after model calls.
- Tool permission checks.
- Human-in-the-loop approvals.
- JSON schema validation.
- Retry/correction loops for invalid model output.

## v1.0 Production API

Goal: stable public API for real Laravel apps.

- Stable contracts.
- Streaming support.
- Model fallback chains.
- Structured output helpers.
- Versioned migrations.
- Documentation site.
- Test suite with fake providers and fake agents.
