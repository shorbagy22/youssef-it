<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when the AI responded successfully (unlike
 * AIServiceUnavailableException) but its answer isn't the valid JSON a
 * structured-output endpoint promised its caller - an LLM asked to
 * "return only JSON" can still preface it with prose, wrap it in a
 * markdown code fence, or emit something that isn't valid JSON at all.
 *
 * Callers (currently DefectAnalysisController) catch this specific
 * exception to map it to a deliberate HTTP 502, distinct from a 503
 * (AI unreachable) or a 500 (a bug in this app).
 */
final class AIResponseInvalidException extends RuntimeException {}
