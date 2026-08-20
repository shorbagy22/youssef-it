<?php

declare(strict_types=1);

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Laravel's built-in 'array' cast json_encode()s with no extra flags,
 * which escapes non-ASCII characters to "\uXXXX" sequences. That
 * happened to still "work" for DataRecord::data because MySQL's native
 * JSON column type re-canonicalizes ANY valid JSON back to literal
 * UTF-8 on storage, regardless of how it was escaped in the original
 * INSERT text - but that's an incidental, undocumented behavior of one
 * specific database engine, not something this app's own code was ever
 * actually guaranteeing. Confirmed as a real problem: SQLite (this
 * app's own test database) has no such JSON type and no such
 * normalization - it stores exactly the escaped text it's given, which
 * silently broke a LIKE-based substring search (ChatDataService's
 * keyword matching) against Arabic content, since the literal Arabic
 * bytes a LIKE '%keyword%' pattern needs to find never actually appear
 * in the "\uXXXX"-escaped stored text.
 *
 * This cast makes the encoding explicit and correct on ANY database
 * driver, rather than relying on MySQL happening to fix it after the
 * fact - JSON_UNESCAPED_UNICODE keeps Arabic (and any other non-ASCII
 * text) as literal UTF-8 characters in the stored JSON text itself.
 */
final class UnicodeJsonCast implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?array
    {
        return $value === null ? null : json_decode($value, true);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return $value === null ? null : json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
