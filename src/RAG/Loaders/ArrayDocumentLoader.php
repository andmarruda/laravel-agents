<?php

namespace Andmarruda\LaravelAgents\RAG\Loaders;

use Andmarruda\LaravelAgents\RAG\Contracts\DocumentLoader;
use Andmarruda\LaravelAgents\RAG\Data\Document;
use InvalidArgumentException;

class ArrayDocumentLoader implements DocumentLoader
{
    /**
     * @param array<int, Document|array<string, mixed>|string> $items
     */
    public function __construct(
        protected array $items,
    ) {
    }

    public function load(): array
    {
        return array_map(function (Document|array|string $item): Document {
            if ($item instanceof Document) {
                return $item;
            }

            if (is_string($item)) {
                return Document::fromText($item);
            }

            if (! is_string($item['content'] ?? null)) {
                throw new InvalidArgumentException('Array documents must contain a string [content] value.');
            }

            return Document::fromText(
                content: $item['content'],
                metadata: is_array($item['metadata'] ?? null) ? $item['metadata'] : [],
                source: is_string($item['source'] ?? null) ? $item['source'] : null,
                mimeType: is_string($item['mime_type'] ?? null) ? $item['mime_type'] : 'text/plain',
            );
        }, $this->items);
    }
}
