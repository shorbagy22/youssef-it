<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when the local Ollama server cannot be reached or fails to
 * respond successfully, after retries are exhausted.
 *
 * Callers (currently ChatController) catch this specific exception rather
 * than a generic one so an Ollama outage can be mapped to a deliberate
 * HTTP 503, matching the standing "return 503 if Ollama is unavailable"
 * architecture decision.
 */
final class OllamaUnavailableException extends RuntimeException {}
