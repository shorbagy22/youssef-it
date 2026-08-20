<?php

declare(strict_types=1);

use App\Models\DataRecord;
use App\Services\AreaScrapDefectCountService;

test('buildPrompt includes the fixed system prompt, appended data, and question', function () {
    $record = DataRecord::factory()->make(['sheet_name' => 'Total', 'row_index' => 3524, 'data' => [46224, 1126312302, 7, 943006420, ' Assembly', 809152438, 'item desc', 4, 'قطع']]);

    $prompt = (new AreaScrapDefectCountService)->buildPrompt(collect([$record]), 'what are the defects on 21/7/2026?');

    expect($prompt)->toContain('Column 4 = Area (e.g., Assembly)')
        ->toContain('DO NOT assume months')
        ->toContain('NEVER say "future date"')
        ->toContain('ABSOLUTELY FORBIDDEN')
        ->toContain('Saying "data is from June only"')
        ->toContain('No defects found for this date')
        ->toContain("QUESTION:\nwhat are the defects on 21/7/2026?")
        ->toContain('"row_index": 3524')
        ->toContain('قطع');
});
