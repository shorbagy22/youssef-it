<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Every raw form a single resolved calendar date could appear as inside
 * a DataRecord's values - see ChatDataService::extractDateQuery(). Sync
 * never reformats anything, so a date cell might be a literal string in
 * any of several formats, or an Excel serial number, depending entirely
 * on how the source workbook stored it.
 */
final class DateQueryMatch
{
    /**
     * @param  list<string>  $strings
     */
    public function __construct(
        public readonly array $strings,
        public readonly int $excelSerial,
    ) {}
}
