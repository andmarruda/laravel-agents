# Evolutionary Architecture

Laravel Agents should grow as a small core plus optional capabilities. The package should avoid locking the public API too early, but it must keep clear boundaries so new modules do not leak infrastructure details into the domain.

## Current Boundary

```txt
src/
  Agents/       Application-facing agent abstractions.
  Models/       Model routing and provider selection.
  Ports/        Stable contracts required by the core.
  Adapters/     Infrastructure implementations of ports.
  Data/         Small immutable response objects.
  Tools/        Tool runtime helpers.
  Contracts/    Compatibility contracts and public extension points.
```

## Ports And Adapters

The core depends on ports, never directly on provider SDKs or HTTP details.

- `Ports\ModelPort` is the model-generation port.
- `Adapters\Models\OpenAiModelAdapter` implements OpenAI chat completions.
- `Adapters\Models\AnthropicModelAdapter` implements Claude messages.
- `Adapters\Models\FireworksModelAdapter` implements Fireworks chat completions.
- `Models\ModelRouter` is the composition boundary that chooses the adapter for a `provider/model` name.

Adapters can change, split, or gain SDK-specific details without forcing changes into `Agent`, `SupervisorAgent`, workflows, memory, or guardrails.

## Evolution Rules

- Add a port when the core needs a capability but should not know the infrastructure.
- Add an adapter when integrating a vendor, storage engine, queue backend, vector store, or observability exporter.
- Keep public DTOs small and explicit.
- Prefer additive APIs until v1.0.
- Mark compatibility shims as deprecated before removing them.
- Build one vertical slice at a time: minimal contract, one adapter, tests, documentation.

## Planned Ports

- `MemoryPort` for threads, messages, working memory, and summaries.
- `WorkflowStorePort` for snapshots, suspend/resume, and step history.
- `McpClientPort` and `McpServerPort` for external tool protocols.
- `TracePort` for observability export.
- `EmbeddingPort` and `VectorStorePort` for RAG.
- `GuardrailPort` for input, output, and tool-execution policies.

## Compatibility

`Contracts\ModelProvider` currently extends `Ports\ModelPort` and is kept as a deprecated compatibility alias. New code should type against `Andmarruda\LaravelAgents\Ports\ModelPort`.
