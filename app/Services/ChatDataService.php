<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\DataRecord;
use Illuminate\Support\Collection;

/**
 * Turns a department's recent DataRecord rows into the prompt sent to
 * Ollama. Pure string composition - no HTTP, no database queries (the
 * caller fetches the records; this class only knows how to describe
 * them). Never receives raw Excel content, only the structured fields
 * SyncSourcesAction already extracted, per the standing "no full file
 * sent to the AI" constraint.
 */
final class ChatDataService
{
    /**
     * @param  Collection<int, DataRecord>  $records  Most recent first.
     */
    public function buildPrompt(Collection $records, string $question): string
    {
        $context = $this->buildContext($records);

        return <<<PROMPT
            You are a factory data assistant. Use only the data below to answer the
            question. Be concise, include specific numbers from the data, and mention
            defects if they are relevant to the question. If the data doesn't answer
            the question, say so plainly instead of guessing.

            Recent department data (most recent first):
            {$context}

            Question: {$question}
            PROMPT;
    }

    /**
     * @param  Collection<int, DataRecord>  $records
     */
    private function buildContext(Collection $records): string
    {
        if ($records->isEmpty()) {
            return 'No data records are available for this department yet.';
        }

        return $records
            ->map(function (DataRecord $record): string {
                $defects = $record->defects !== null && $record->defects !== []
                    ? implode(', ', $record->defects)
                    : 'none';

                return sprintf(
                    '- %s: NRFT=%s, PPM=%s, Defects=%s',
                    $record->date->toDateString(),
                    $record->nrft ?? 'N/A',
                    $record->ppm ?? 'N/A',
                    $defects,
                );
            })
            ->implode("\n");
    }
}
