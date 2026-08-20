<?php

declare(strict_types=1);

namespace App\Imports;

use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * Streams a PDF's text one OCR'd line at a time - the opt-in fallback
 * (Source::$ocr) for a PDF whose embedded text layer is unreadable at
 * the SOURCE, a different and deeper problem than the one
 * PdfTextImport::fixArabicRuns() fixes.
 *
 * PdfTextImport fixes character-level reversal/presentation-form
 * glyphs - it assumes the extracted text at least has correct word
 * BOUNDARIES to work with (spaces between words), and puts each word's
 * own letters back in order. Confirmed against a real synced PDF where
 * that assumption doesn't hold: smalot/pdfparser's own text extraction
 * glues adjacent words together with NO separator at all - e.g. "تاريخ"
 * and "الإصدار" extracted as one unbroken run, which PdfTextImport's
 * per-run reversal can only turn into "الإصدارتاريخ" (right letters,
 * wrong word split - it has no way to know a boundary was ever there).
 * The same document also has plain English labels reversed
 * character-by-character (e.g. "Printed If Uncontrolled" extracted as
 * "dellortnocnU fI detnirP") via a 'ReversedChars' marked-content span
 * in the PDF itself - not Arabic, so PdfTextImport's regex never
 * touches it either. Neither is fixable from the extracted text alone:
 * there's no position or word-boundary information left in it to
 * reconstruct from.
 *
 * This class sidesteps the broken text layer entirely by re-deriving
 * text from the page's actual VISUAL layout instead: each page is
 * rendered to a PNG (Poppler's pdftoppm) and then read with Tesseract
 * OCR (Arabic + English). Confirmed, on that same real PDF, to produce
 * fully correct spacing, word order, and reading order for both scripts
 * - because OCR reads pixels, it never depends on how (or whether) the
 * PDF's own content stream encoded spacing or glyph order.
 *
 * Deliberately NOT the default PDF path (see SyncSourcesAction::
 * importerFor()): OCR costs real seconds PER PAGE rather than one parse
 * of the whole document, and can introduce its OWN misreads on a
 * document whose text layer already extracts cleanly. An admin opts a
 * specific Source into this via the "Use OCR" checkbox when they've
 * confirmed (like here) that PdfTextImport's output is unusable for it.
 *
 * Shells out to two external binaries - see config/pdf_ocr.php for how
 * their paths (and Tesseract's language data directory) are configured.
 * Neither is a composer dependency; both must be installed separately
 * on whatever machine runs the sync.
 */
final class OcrPdfTextImport implements RowStreamer
{
    /**
     * @param  callable(int $sheetIndex, string $sheetName, int $rowIndex, array<string, mixed> $values): void  $onRow
     */
    public function stream(string $path, callable $onRow): void
    {
        $workDir = storage_path('app'.DIRECTORY_SEPARATOR.'tmp'.DIRECTORY_SEPARATOR.'ocr_'.bin2hex(random_bytes(8)));

        if (! mkdir($workDir, recursive: true) && ! is_dir($workDir)) {
            throw new RuntimeException("Could not create OCR working directory: {$workDir}");
        }

        try {
            $this->renderPagesToImages($path, $workDir);

            $lineIndex = 0;
            $maxPages = (int) config('pdf_ocr.max_pages');

            foreach ($this->sortedPageImages($workDir) as $pageNumber => $imagePath) {
                if ($pageNumber >= $maxPages) {
                    break;
                }

                foreach ($this->linesOf($imagePath) as $text) {
                    $lineIndex++;
                    $onRow(0, 'pdf-ocr', $lineIndex, ['line' => $lineIndex, 'text' => $text]);
                }
            }
        } finally {
            $this->removeDirectory($workDir);
        }
    }

    private function renderPagesToImages(string $path, string $workDir): void
    {
        $binary = (string) config('pdf_ocr.pdftoppm_binary');

        $result = Process::timeout((int) config('pdf_ocr.render_timeout'))
            ->run([
                $binary,
                '-png',
                '-r', (string) config('pdf_ocr.dpi'),
                $path,
                $workDir.DIRECTORY_SEPARATOR.'page',
            ]);

        if ($result->failed()) {
            throw new RuntimeException(
                "Could not render PDF pages to images (pdftoppm, binary: \"{$binary}\" - ".
                'set PDF_OCR_PDFTOPPM_BINARY if it is not on PATH): '.
                trim($result->errorOutput() !== '' ? $result->errorOutput() : $result->output())
            );
        }
    }

    /**
     * @return list<string> PNG paths, one per rendered page, in page order.
     */
    private function sortedPageImages(string $workDir): array
    {
        $files = glob($workDir.DIRECTORY_SEPARATOR.'page-*.png') ?: [];
        sort($files, SORT_NATURAL);

        return $files;
    }

    /**
     * @return list<string>
     */
    private function linesOf(string $imagePath): array
    {
        $binary = (string) config('pdf_ocr.tesseract_binary');

        $result = Process::timeout((int) config('pdf_ocr.ocr_timeout'))
            ->run([
                $binary,
                $imagePath,
                'stdout',
                '-l', (string) config('pdf_ocr.languages'),
                '--tessdata-dir', (string) config('pdf_ocr.tessdata_dir'),
            ]);

        if ($result->failed()) {
            throw new RuntimeException(
                "OCR failed (tesseract, binary: \"{$binary}\" - ".
                'set PDF_OCR_TESSERACT_BINARY if it is not on PATH) on '.
                basename($imagePath).': '.
                trim($result->errorOutput() !== '' ? $result->errorOutput() : $result->output())
            );
        }

        $lines = preg_split('/\r\n|\r|\n/', $result->output()) ?: [];

        return array_values(array_filter(
            array_map(trim(...), $lines),
            fn (string $line): bool => $line !== '',
        ));
    }

    private function removeDirectory(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        foreach (glob($dir.DIRECTORY_SEPARATOR.'*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($dir);
    }
}
