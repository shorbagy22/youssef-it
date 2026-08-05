<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when Microsoft Graph / SharePoint cannot be reached, fails to
 * authenticate, or fails to respond successfully.
 *
 * Callers catch this specific exception rather than a generic one so a
 * SharePoint outage can be handled deliberately (the sync command marks
 * the affected file Failed and continues, rather than crashing the whole
 * run).
 */
final class SharePointException extends RuntimeException {}
