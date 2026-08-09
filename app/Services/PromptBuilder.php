<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Builds the two pieces of text sent to the AI service for each chat
 * request: the system prompt (the assistant's persona and behavior
 * rules) and the user prompt (conversation history flattened into a
 * single block of text, ending with the current question).
 *
 * Pure string composition - no I/O, no config access, no knowledge of
 * HTTP or the AI service's request format. That keeps it trivial to unit
 * test and safe to reuse if the LLM client ever changes.
 */
final class PromptBuilder
{
    /**
     * Build the system prompt describing who the assistant is and how it
     * should behave.
     *
     * @param  string|null  $context  Reserved grounding-context seam, unused
     *                                today. Data ingestion and any retrieval-augmented generation
     *                                are owned entirely by the company's AI service now - Laravel
     *                                has no document store of its own to pass context from.
     */
    public function buildSystemPrompt(?string $context = null): string
    {
        $prompt = 'You are the CompanyAIChatbot assistant, an internal tool that helps '
            .'company employees. Answer clearly and concisely - prefer short, direct '
            .'answers over long explanations unless the user explicitly asks for more '
            .'detail. You do not have access to any company documents right now; if a '
            .'question requires information you cannot know without them, say so '
            .'plainly instead of guessing.';

        if ($context !== null) {
            $prompt .= "\n\nUse the following company document excerpts to answer, when relevant:\n{$context}";
        }

        return $prompt;
    }

    /**
     * Build the user-facing prompt: prior conversation turns formatted as
     * "User: ..." / "Assistant: ..." lines, followed by the new message.
     *
     * @param  array<int, array{role: string, content: string}>  $history
     */
    public function buildUserPrompt(string $message, array $history = []): string
    {
        if ($history === []) {
            return $message;
        }

        $turns = [];

        foreach ($history as $turn) {
            $speaker = $turn['role'] === 'assistant' ? 'Assistant' : 'User';
            $turns[] = "{$speaker}: {$turn['content']}";
        }

        $turns[] = "User: {$message}";

        return implode("\n", $turns);
    }
}
