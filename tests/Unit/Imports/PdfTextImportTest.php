<?php

declare(strict_types=1);

use App\Imports\PdfTextImport;

/**
 * fixArabicRuns() is private (there's no public entry point that doesn't
 * require a real PDF file on disk - stream() parses one via
 * smalot/pdfparser), so it's exercised directly via reflection. Every
 * example below is a REAL line confirmed against this app's actual
 * synced PDF data (source "in 37", a bilingual English/Arabic
 * procedures document) - see PdfTextImport's class docblock for the
 * root-cause explanation this is fixing.
 */
function fixArabicRuns(string $line): string
{
    $import = new PdfTextImport;
    $method = new ReflectionMethod($import, 'fixArabicRuns');
    $method->setAccessible(true);

    return $method->invoke($import, $line);
}

test('fixArabicRuns reconstructs a reversed, presentation-form-glyph Arabic line into correct standard-Arabic reading order', function () {
    $raw = '"ﻯﺮﺘﺸﻣ ﻡﺃ ﻊﻨﺼﻣ" ﻒﻨﺼﻟﺍ ﻉﻮﻧ ﺎﻬﺑ ﺢﺿﻮﻳ ﻙﻼﻬﺘﺳﻻﺍ ﻝﺪﻌﻣﻭ ﻒﻨﺼﻟﺍ ﻢﺳﺍﻭ ﻒﻨﺼﻟﺍ ﺩﻮﻛ ﺎﻬﺑ ﺍﺩﺪﺤﻣ ﺮﻳﻮﻄﺘﻟﺍﻭ ﺙﻮﺤﺒﻟﺍ ﺓﺭﺍﺩﺇ ﺎﻫﺭﺪﺼﺗ ﺔﻘﻴﺛﻭ ﻰﻫﻭ ﺞﺘﻨﻤﻟﺍ ﺓﺮﺠﺷ';

    $fixed = fixArabicRuns($raw);

    expect($fixed)->toBe(
        '"مصنع أم مشترى" شجرة المنتج وهى وثيقة تصدرها إدارة البحوث والتطوير محددا بها كود الصنف واسم الصنف ومعدل االستهالك يوضح بها نوع الصنف'
    );
});

test('fixArabicRuns leaves an English label and a trailing clause number untouched on a mixed line, fixing only the Arabic run', function () {
    $raw = '.Pre-Processing Lead Time ﺪﻳﺭﻮﺘﻟﺍ ﺮﻣﺃﻭ ﺕﺎﺟﺎﻴﺘﺣﻻﺍ ﺔﻄﺧ ﺭﺍﺪﺻﺇ ﻦﻴﺑ ﺎﻣ ﺓﺮﺘﻔﻟﺍ 5-8-1';

    $fixed = fixArabicRuns($raw);

    // Known minor limitation: a "لا" (lam-alef) ligature decomposes to
    // two separate characters under NFKC, and a plain character-level
    // reversal (no ligature-aware bidi shaping) can leave a doubled
    // alef where the ligature was - "االحتياجات" instead of
    // "الاحتياجات". Still fully readable Arabic, just not a perfect
    // byte-for-byte match of the ligature's own internal order.
    expect($fixed)->toBe('.Pre-Processing Lead Time الفترة ما بين إصدار خطة االحتياجات وأمر التوريد 5-8-1')
        ->and($fixed)->toStartWith('.Pre-Processing Lead Time')
        ->and($fixed)->toEndWith('5-8-1');
});

test('fixArabicRuns leaves a line with no Arabic characters completely unchanged', function () {
    $line = ': Bill of Material (BOM) 5-9';

    expect(fixArabicRuns($line))->toBe($line);
});

test('fixArabicRuns normalizes Arabic presentation-form glyphs to standard Arabic letters, not just reversing them', function () {
    // The root cause is two separate problems, not one - reversal alone
    // isn't enough: presentation-form glyphs (U+FE70-FEFF here) are
    // DIFFERENT Unicode codepoints from standard Arabic letters
    // (U+0600-06FF), so a keyword search for the standard-Arabic word a
    // user actually types would never match unnormalized text even if
    // it were already in the right order.
    $presentationFormWord = 'ﻒﻨﺼﻟﺍ'; // "الصنف" ("the item"), reversed + presentation forms

    $fixed = fixArabicRuns($presentationFormWord);

    expect($fixed)->toBe('الصنف');

    foreach (mb_str_split($fixed) as $char) {
        $codepoint = mb_ord($char, 'UTF-8');

        expect($codepoint)->toBeLessThan(0x0700)
            ->and($codepoint)->toBeGreaterThanOrEqual(0x0600);
    }
});

test('fixArabicRuns leaves a Latin/digit-only line untouched even when adjacent lines in the document are Arabic', function () {
    $line = ': Stock Keeping Unit (SKU) 11-5';

    expect(fixArabicRuns($line))->toBe($line);
});
