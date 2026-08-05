<?php

declare(strict_types=1);

namespace App\DTOs;

/**
 * A validated chat message from the user, plus the prior conversation
 * turns needed to give the model context.
 *
 * Built by ChatController from validated request input; carried through
 * ChatAction into ChatService without either layer touching the raw
 * HTTP request.
 */
final readonly class ChatRequest
{
    /**
     * @param  array<int, array{role: string, content: string}>  $history
     *                                                                     Prior turns in chronological order, oldest first. Each
     *                                                                     entry's "role" is "user" or "assistant".
     */
    public function __construct(
        public string $message,
        public array $history = [],
    ) {}
}
