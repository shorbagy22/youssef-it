<?php

declare(strict_types=1);

namespace App\Actions;

use App\DTOs\ChatRequest;
use App\DTOs\ChatResponse;
use App\Exceptions\AIServiceUnavailableException;
use App\Services\ChatService;

/**
 * The use-case ChatController calls: answer one chat message.
 *
 * Thin by design, matching GetSystemStatusAction's convention - the real
 * logic lives in ChatService. Keeping this as its own class (rather than
 * having the controller call ChatService directly) gives Controllers a
 * single, consistent "call an Action" pattern regardless of how much
 * logic sits behind it.
 */
final class ChatAction
{
    public function __construct(
        private readonly ChatService $chatService,
    ) {}

    /**
     * @throws AIServiceUnavailableException
     */
    public function handle(ChatRequest $request): ChatResponse
    {
        return $this->chatService->handle($request);
    }
}
