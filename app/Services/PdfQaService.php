<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\DataRecord;
use App\Services\Support\RawRowPayload;
use Illuminate\Support\Collection;

/**
 * Builds the prompt for POST /api/pdf/ask - exact-extraction Q&A
 * against ONE specific PDF document's text, not a department's mixed
 * data. The strictest and narrowest of this app's five AI services:
 *
 * - ChatDataService: free-form Q&A across a department's rows
 *   (Excel AND PDF mixed together), relevance-filtered.
 * - DataAnalysisService: structure detection + optional Q&A, JSON
 *   output, corruption-gated, department-wide by default.
 * - This one: exact-extraction Q&A against a single PDF's raw,
 *   possibly broken/misordered extracted text - PdfTextImport's
 *   extraction can leave real documents reordered or fragmented, but
 *   the prompt insists the information still EXISTS in there somewhere
 *   and the AI must search ALL of it before ever answering "not
 *   found", rather than giving up at the first sign of broken
 *   structure. Never summarization/translation/invention - only exact
 *   text as written in the document. Output is always two parts: the
 *   matching text, then the direct answer - never prose ABOUT the
 *   document. This is why PdfQaController requires source_id and
 *   rejects a non-PDF source outright, rather than accepting a
 *   department-wide row set the way DataReadabilityService/
 *   DataAnalysisService optionally narrow to one source.
 *
 * Unlike this app's other prompt-building services, the system prompt
 * here is a literal TEMPLATE with {{DOCUMENT_TEXT}} and {{QUESTION}}
 * placeholders embedded inside fixed section markers, rather than
 * something to append DATA/QUESTION sections after - buildPrompt()
 * does a straight string substitution into those markers instead of
 * concatenation, so the document text and question land exactly where
 * the prompt places them, not wherever appending would put them.
 *
 * No relevance filtering here either, for the same reason as the other
 * non-chat services - the AI needs the document's own text, in order,
 * to search it itself; row volume is bounded by the caller instead (see
 * PdfQaController::MAX_ROWS).
 *
 * HONESTLY STATED, STILL-OPEN LIMITATION: "no relevance filtering" only
 * actually works if the WHOLE document's text fits in Ollama's context
 * window (see OllamaClient::generate()'s num_ctx option / config/ollama
 * .php's context_window docblock) - a real, confirmed problem when it
 * doesn't. This app's configured Ollama server hits a severe real
 * performance cliff well before this app's larger real PDF documents'
 * full token count (confirmed live: a ~17,000-token document's request
 * exceeded a 5-minute wait at 24576 tokens of context, but completed in
 * ~38s at 8192), so context_window is deliberately capped at the
 * largest value confirmed fast on that real server, NOT the largest
 * value that would fit this app's biggest documents whole. For a
 * document whose text exceeds that budget, Ollama silently drops the
 * OLDEST tokens - content near the START of a long document can still
 * go unseen by the model, same failure mode as before, just requiring a
 * larger document to trigger it now instead of any PDF at all. The
 * real, complete fix is relevance-filtering this service's document
 * text the way ChatDataService/DefectQueryService already do for Excel
 * rows (only send the rows actually relevant to the question, not the
 * whole document) - not yet implemented here, since it's a genuinely
 * different, larger change from a config value.
 */
final class PdfQaService
{
    private const string SYSTEM_PROMPT_TEMPLATE = <<<'PROMPT'
        You are reading a PDF document with possibly broken or misordered text.

        IMPORTANT:
        - The text may be messy, but the information EXISTS
        - You MUST search ALL content before answering

        ========================
        DOCUMENT (RAW TEXT)
        ========================
        {{DOCUMENT_TEXT}}
        ========================

        USER QUESTION:
        {{QUESTION}}

        TASK:
        - Find the exact definition or mention of the user's question
        - Extract the answer EXACTLY as written in the document

        RULES:
        - DO NOT say "not found" unless you searched everything
        - DO NOT rely on structure (it may be broken)
        - DO NOT summarize
        - DO NOT guess
        - ALWAYS extract real text from the document

        OUTPUT:
        1. Matching text from document
        2. Final answer (clear and short)
        PROMPT;

    /**
     * @param  Collection<int, DataRecord>  $records  A single PDF
     *         source's rows, in document order - see class docblock.
     */
    public function buildPrompt(Collection $records, string $question): string
    {
        return str_replace(
            ['{{DOCUMENT_TEXT}}', '{{QUESTION}}'],
            [RawRowPayload::json($records), $question],
            self::SYSTEM_PROMPT_TEMPLATE,
        );
    }
}
