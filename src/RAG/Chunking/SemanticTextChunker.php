<?php

namespace Andmarruda\LaravelAgents\RAG\Chunking;

use Andmarruda\LaravelAgents\RAG\Data\Document;
use Andmarruda\LaravelAgents\RAG\Data\DocumentChunk;

class SemanticTextChunker extends RecursiveCharacterChunker
{
    public function __construct(int $chunkSize = 1000, int $overlap = 150)
    {
        parent::__construct($chunkSize, $overlap, ["\n\n", "\n", '. ', ' ', '']);
    }

    public function chunk(Document $document): array
    {
        $blocks = preg_split('/\n{2,}/', trim($document->content), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $contents = [];
        $buffer = '';

        foreach ($blocks as $block) {
            $block = trim($block);
            $candidate = $buffer === '' ? $block : $buffer."\n\n".$block;

            if (strlen($candidate) <= $this->chunkSize) {
                $buffer = $candidate;
                continue;
            }

            if ($buffer !== '') {
                $contents[] = $buffer;
                $buffer = '';
            }

            if (strlen($block) > $this->chunkSize) {
                foreach (parent::chunk(Document::fromText($block, source: $document->source, mimeType: $document->mimeType)) as $chunk) {
                    $contents[] = $chunk->content;
                }
            } else {
                $buffer = $block;
            }
        }

        if ($buffer !== '') {
            $contents[] = $buffer;
        }

        return array_map(fn (string $content, int $index) => new DocumentChunk(
            id: hash('sha256', $document->id."\0".$index."\0".$content),
            documentId: $document->id,
            content: $content,
            index: $index,
            metadata: [
                ...$document->metadata,
                'document_id' => $document->id,
                'chunk_index' => $index,
                'source' => $document->source,
                'mime_type' => $document->mimeType,
                'checksum' => $document->checksum,
                'chunking_strategy' => 'semantic',
                'chunking_version' => '1',
            ],
        ), $contents, array_keys($contents));
    }
}
