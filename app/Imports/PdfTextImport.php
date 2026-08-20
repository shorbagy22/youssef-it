<?php

declare(strict_types=1);

namespace App\Imports;

use Normalizer;
use Smalot\PdfParser\Page;
use Smalot\PdfParser\Parser;
use Throwable;

/**
 * Streams a PDF's text one extracted line at a time, calling $onRow for
 * each - the PDF counterpart to RawRowsImport, sharing its RowStreamer
 * contract so SyncSourcesAction doesn't need to know which kind of
 * source it's syncing.
 *
 * No structure is assumed and nothing is interpreted: every non-blank
 * line of extractable text is emitted as-is, in the order the PDF's own
 * pages and text stream produce it. "Line" here means whatever
 * smalot/pdfparser's own text extraction considers a line break in the
 * PDF's content stream - there's no concept of a "row" or "column" in a
 * PDF, so unlike RawRowsImport this never claims to preserve the
 * document's original visual layout, only its raw text content, in
 * order.
 *
 * Blank/whitespace-only lines are dropped, not stored as empty rows -
 * they carry no text content, and PDFs commonly have many of them
 * (paragraph spacing, page breaks), which would otherwise bloat
 * data_records with pure noise. This never drops a line with real
 * content, only ones that already have none - the same principle
 * RawRowsImport applies to trailing empty spreadsheet columns, applied
 * here to genuinely empty lines instead. Line numbers are assigned
 * sequentially to the lines that ARE kept (1, 2, 3, ...), not to the
 * PDF's raw line positions, so there are no numbering gaps to explain.
 *
 * IMPORTANT, honestly stated limitation: unlike RawRowsImport, which
 * uses PhpSpreadsheet's IReadFilter to stop CONSTRUCTING cells beyond a
 * bound before they're ever built, smalot/pdfparser has no equivalent
 * mechanism - Parser::parseFile() must read and parse the entire PDF's
 * object graph into memory before this class can extract so much as one
 * line from it. MAX_PAGES below bounds how much gets streamed/inserted
 * out of an already-parsed document, and the file-size ceiling in
 * SyncSourcesAction::validateFile() is the only real upfront defense
 * against an oversized PDF - there is no way to make the parse step
 * itself bounded the way Excel's chunked, filtered load() calls are.
 *
 * ROOT CAUSE of a real, confirmed problem ("why can't the AI read the
 * PDF data") fixed here, at extraction time: smalot/pdfparser (like
 * most PDF text extractors) reads a page's content stream in raw glyph-
 * placement order, with no Unicode Bidi (UAX #9) reordering applied.
 * For an Arabic PDF, that raw order is doubly wrong compared to the
 * correct logical reading order - confirmed directly against this
 * app's real synced PDFs:
 *
 * 1. Each Arabic run's GLYPHS were placed into the content stream in
 *    the order they render on the page (right-to-left), so naive
 *    left-to-right extraction yields that run reversed - "شجرة المنتج"
 *    ("the product tree") comes out as "ﺞﺘﻨﻤﻟﺍ ﺓﺮﺠﺷ".
 * 2. The font this PDF was built with maps to Arabic PRESENTATION FORM
 *    glyphs (Unicode blocks U+FB50-FDFF/U+FE70-FEFF - shape-specific
 *    glyph variants meant for rendering) rather than standard logical
 *    Arabic letters (U+0600-06FF) - a real problem beyond readability:
 *    those are different Unicode codepoints entirely, so a stored
 *    presentation-form string doesn't even keyword-match the standard-
 *    Arabic word a user actually types when asking a question.
 *
 * fixArabicRuns() reverses this, per line: every maximal run of Arabic
 * characters (either block, with embedded whitespace) is NFKC-
 * normalized (which canonicalizes presentation-form glyphs back to
 * their base letters) and reversed back to logical reading order, IN
 * PLACE - a mixed line's non-Arabic runs (English words, numbers,
 * punctuation, like a clause number "5-8-1" or an English label) are
 * left completely untouched, both in content and position, since only
 * the Arabic runs were ever glyph-reversed to begin with. Confirmed
 * against this app's real data: an unreadable line like
 * '.Pre-Processing Lead Time ﺪﻳﺭﻮﺘﻟﺍ ﺮﻣﺃﻭ ﺕﺎﺟﺎﻴﺘﺣﻻﺍ ﺔﻄﺧ ﺭﺍﺪﺻﺇ ﻦﻴﺑ ﺎﻣ ﺓﺮﺘﻔﻟﺍ 5-8-1'
 * becomes the fully correct
 * '.Pre-Processing Lead Time الفترة ما بين إصدار خطة الاحتياجات وأمر التوريد 5-8-1'.
 * This can only fix data synced AFTER this change - already-synced PDF
 * sources need a re-sync to have their existing rows corrected.
 */
final class PdfTextImport implements RowStreamer
{
    private const int MAX_PAGES = 2000;

    private const string SHEET_NAME = 'pdf';

    /**
     * @param  callable(int $sheetIndex, string $sheetName, int $rowIndex, array<string, mixed> $values): void  $onRow
     */
    public function stream(string $path, callable $onRow): void
    {
        $document = (new Parser)->parseFile($path);
        $pages = $document->getPages();

        $lineIndex = 0;

        foreach ($pages as $pageNumber => $page) {
            if ($pageNumber >= self::MAX_PAGES) {
                break;
            }

            foreach ($this->linesOf($page) as $text) {
                $lineIndex++;
                $onRow(0, self::SHEET_NAME, $lineIndex, ['line' => $lineIndex, 'text' => $text]);
            }
        }
    }

    /**
     * A single malformed page's content stream shouldn't take down the
     * whole PDF's extraction - a scanned/image-only page legitimately
     * has no extractable text either (getText() returns an empty
     * string, not an error), which this treats the same way: zero lines
     * from that page, extraction continues.
     *
     * @return list<string>
     */
    private function linesOf(Page $page): array
    {
        try {
            $text = $page->getText();
        } catch (Throwable) {
            return [];
        }

        $lines = preg_split('/\r\n|\r|\n/', $text) ?: [];

        return array_values(array_filter(
            array_map($this->fixArabicRuns(...), array_map(trim(...), $lines)),
            fn (string $line): bool => $line !== '',
        ));
    }

    /**
     * Every maximal run of Arabic characters (standard block U+0600-06FF
     * or presentation forms U+FB50-FDFF/U+FE70-FEFF, with embedded
     * whitespace) is NFKC-normalized and reversed back to logical
     * reading order, in place - see class docblock for why this is
     * needed at all and worked examples of the transform. A run must
     * start and end on an actual Arabic character, so surrounding
     * (non-Arabic) whitespace and punctuation is never pulled into it
     * and left untouched, same as any other non-Arabic content on the
     * line (English words, numbers, a clause number like "5-8-1").
     *
     * Guarded by class_exists('Normalizer') rather than assuming ext-intl
     * is present - this app has no other dependency on it (composer.json
     * doesn't require ext-intl), so an environment without it should
     * degrade to "leave PDF Arabic text as extracted" rather than a
     * fatal error on every PDF sync.
     */
    private function fixArabicRuns(string $line): string
    {
        if (! class_exists(Normalizer::class)) {
            return $line;
        }

        $pattern = '/[\x{0600}-\x{06FF}\x{FB50}-\x{FDFF}\x{FE70}-\x{FEFF}]'
            .'(?:[\x{0600}-\x{06FF}\x{FB50}-\x{FDFF}\x{FE70}-\x{FEFF}\s]*'
            .'[\x{0600}-\x{06FF}\x{FB50}-\x{FDFF}\x{FE70}-\x{FEFF}])?/u';

        return preg_replace_callback($pattern, function (array $match): string {
            $normalized = Normalizer::normalize($match[0], Normalizer::FORM_KC);
            $normalized = $normalized !== false ? $normalized : $match[0];

            return implode('', array_reverse(mb_str_split($normalized)));
        }, $line) ?? $line;
    }
}
