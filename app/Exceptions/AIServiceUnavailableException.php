<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when the company's AI HTTP endpoint cannot be reached or fails
 * to respond successfully, after retries are exhausted.
 *
 * Callers (currently ChatController) catch this specific exception
 * rather than a generic one so an outage can be mapped to a deliberate
 * HTTP 503.
 */
final class AIServiceUnavailableException extends RuntimeException {}
