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
            You are a factory assistant.

            Answer based ONLY on this data:

            {$context}

            Question:
            {$question}
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
                    "Date: %s\nNRFT: %s\nPPM: %s\nDefects: %s",
                    $record->date->toDateString(),
                    $record->nrft ?? 'N/A',
                    $record->ppm ?? 'N/A',
                    $defects,
                );
            })
            ->implode("\n\n");
    }
}
