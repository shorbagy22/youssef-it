<?php

declare(strict_types=1);

namespace App\DTOs;

/**
 * The AI service's answer to a ChatRequest.
 *
 * Deliberately just an answer - Laravel has no document retrieval of its
 * own to attribute answers to; the company's AI service owns data
 * ingestion and grounding entirely.
 */
final readonly class ChatResponse
{
    public function __construct(
        public string $answer,
    ) {}
}
