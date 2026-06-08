<?php

namespace Andmarruda\LaravelAgents\RAG\Workflows;

use Andmarruda\LaravelAgents\RAG\Contracts\DocumentLoader;
use Andmarruda\LaravelAgents\RAG\Loaders\ArrayDocumentLoader;
use Andmarruda\LaravelAgents\RAG\RagIndexer;
use Andmarruda\LaravelAgents\Workflows\Step;
use Andmarruda\LaravelAgents\Workflows\WorkflowContext;
use InvalidArgumentException;

class IndexDocumentsStep implements Step
{
    public function __construct(
        protected RagIndexer $indexer,
        protected ?string $namespace = null,
    ) {
    }

    public function handle(mixed $input, WorkflowContext $context): array
    {
        if ($input instanceof DocumentLoader) {
            return $this->indexer->index($input, $this->namespace);
        }

        if (is_array($input)) {
            $documents = array_key_exists('content', $input) ? [$input] : $input;

            return $this->indexer->index(new ArrayDocumentLoader($documents), $this->namespace);
        }

        throw new InvalidArgumentException('IndexDocumentsStep input must be a DocumentLoader or document array.');
    }
}
