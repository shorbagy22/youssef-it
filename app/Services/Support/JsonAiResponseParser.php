<?php

declare(strict_types=1);

namespace App\Services\Support;

use App\Exceptions\AIResponseInvalidException;

/**
 * Shared JSON-reply parsing for any AI service whose system prompt
 * demands a strict JSON response (DefectAnalysisService, and
 * DataAnalysisService) - strips a markdown code fence if the whole
 * reply is wrapped in one (a very common way an LLM ignores "no
 * markdown code fences" in its instructions), then decodes. Services
 * whose prompt has no fixed output contract (ChatDataService,
 * DataReadabilityService) don't use this - their AI reply is returned
 * to the caller as free text, as-is.
 */
final class JsonAiResponseParser
{
    /**
     * @return array<string, mixed>
     *
     * @throws AIResponseInvalidException if the reply isn't valid JSON,
     *                                    even after stripping a code fence.
     */
    public static function parse(string $answer): array
    {
        $stripped = self::stripCodeFence(trim($answer));

        $decoded = json_decode($stripped, true);

        if (! is_array($decoded)) {
            throw new AIResponseInvalidException(
                "AI response was not valid JSON: {$answer}",
            );
        }

        return $decoded;
    }

    private static function stripCodeFence(string $text): string
    {
        if (preg_match('/^```(?:json)?\s*(.*?)\s*```$/s', $text, $matches) === 1) {
            return $matches[1];
        }

        return $text;
    }
}
