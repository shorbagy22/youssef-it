<?php

declare(strict_types=1);

namespace App\DTOs;

/**
 * The model's answer to a ChatRequest.
 *
 * Deliberately just an answer for now - no "sources" field, since Phase 2
 * has no document retrieval to attribute answers to. That field can be
 * added here additively once SharePoint retrieval lands, without any
 * other layer needing to change.
 */
final readonly class ChatResponse
{
    public function __construct(
        public string $answer,
    ) {}
}
