<?php

declare(strict_types=1);

namespace App\ValueObjects;

/**
 * The fixed set of factory departments this system supports.
 *
 * A backed enum keeps every valid department value in one place instead
 * of duplicating a string list across validation rules, seeders, and
 * queries. Values are lowercase - they're used directly in API payloads
 * and URLs (e.g. {"department": "it"}), never displayed as-is.
 */
enum Department: string
{
    case Quality = 'quality';
    case IT = 'it';
    case Safety = 'safety';
    case Maintenance = 'maintenance';
}
