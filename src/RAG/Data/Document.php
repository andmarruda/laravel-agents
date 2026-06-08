<?php

namespace Andmarruda\LaravelAgents\RAG\Data;

class Document
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public readonly string $id,
        public readonly string $content,
        public readonly array $metadata = [],
        public readonly ?string $source = null,
        public readonly string $mimeType = 'text/plain',
        public readonly ?string $checksum = null,
    ) {
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public static function fromText(string $content, array $metadata = [], ?string $source = null, string $mimeType = 'text/plain'): self
    {
        $checksum = hash('sha256', $content);

        return new self(
            id: hash('sha256', $source ?? $checksum),
            content: $content,
            metadata: MetadataNormalizer::normalize($metadata),
            source: $source,
            mimeType: $mimeType,
            checksum: $checksum,
        );
    }
}
