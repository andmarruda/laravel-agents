<?php

namespace Andmarruda\LaravelAgents\RAG\Chunking;

use Andmarruda\LaravelAgents\RAG\Data\Document;
use Andmarruda\LaravelAgents\RAG\Data\DocumentChunk;
use ParseError;

class PhpCodeChunker extends CodeChunker
{
    public function chunk(Document $document): array
    {
        try {
            $tokens = token_get_all($document->content, TOKEN_PARSE);
        } catch (ParseError) {
            return parent::chunk($document);
        }

        $segments = [];
        $buffer = '';
        $depth = 0;
        $declarations = [T_CLASS, T_INTERFACE, T_TRAIT, T_FUNCTION];

        if (defined('T_ENUM')) {
            $declarations[] = T_ENUM;
        }

        foreach ($tokens as $token) {
            $text = is_array($token) ? $token[1] : $token;
            $id = is_array($token) ? $token[0] : null;

            if ($depth === 0 && $id !== null && in_array($id, $declarations, true) && trim($buffer) !== '') {
                $segments[] = $buffer;
                $buffer = '';
            }

            $buffer .= $text;

            if (! is_array($token)) {
                $depth += $token === '{' ? 1 : 0;
                $depth -= $token === '}' ? 1 : 0;
            }
        }

        if (trim($buffer) !== '') {
            $segments[] = $buffer;
        }

        if (count($segments) > 1 && strlen(trim($segments[0])) < 120) {
            $segments[1] = rtrim($segments[0])."\n".ltrim($segments[1]);
            array_shift($segments);
        }

        $contents = [];
        foreach ($segments as $segment) {
            if (strlen($segment) <= $this->chunkSize) {
                $contents[] = trim($segment);
                continue;
            }

            foreach (parent::chunk(Document::fromText($segment, source: $document->source, mimeType: $document->mimeType)) as $chunk) {
                $contents[] = $chunk->content;
            }
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
                'chunking_strategy' => 'php',
                'chunking_version' => '1',
            ],
        ), $contents, array_keys($contents));
    }
}
