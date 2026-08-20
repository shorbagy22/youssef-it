<?php

declare(strict_types=1);

use App\Models\DataRecord;
use App\Services\Support\RawRowPayload;

test('json() preserves Arabic text literally, not as escaped unicode sequences', function () {
    $record = DataRecord::factory()->make(['row_index' => 1, 'data' => ['line' => 1, 'text' => 'قطع']]);

    $json = RawRowPayload::json(collect([$record]));

    $escapedForm = json_encode('قطع');
    $literalEscapePrefix = '\\u06';

    // Regression test for a real, confirmed bug: json_encode() escapes
    // non-ASCII characters by default, so without JSON_UNESCAPED_UNICODE
    // this string would contain the escaped form (e.g. "قطع")
    // instead of the literal characters - technically still valid JSON,
    // but it broke a downstream test asserting the Arabic defect word
    // literally appeared in an AI prompt built from this payload.
    expect($json)->toContain('قطع')
        ->not->toContain($literalEscapePrefix);

    expect($escapedForm)->toContain($literalEscapePrefix); // sanity-check the escaped form really does look like this
});
