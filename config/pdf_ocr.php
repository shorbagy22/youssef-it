<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| PDF OCR Configuration
|--------------------------------------------------------------------------
|
| Used by App\Imports\OcrPdfTextImport - the OPT-IN (Source::$ocr)
| fallback for a PDF whose embedded text layer extracts unreadable even
| after App\Imports\PdfTextImport's own fix (see that class's docblock,
| and OcrPdfTextImport's, for the two DIFFERENT root causes each one
| actually solves - they are not the same bug).
|
| This shells out to two external binaries, NEITHER a composer
| dependency - both must be installed separately on whatever machine
| runs a sync (dev machine or server):
| - Poppler's pdftoppm, to render each PDF page to a PNG.
| - Tesseract OCR, to read text off each rendered PNG.
|
*/

return [

    // Absolute path to pdftoppm(.exe), or just 'pdftoppm' if it's on
    // PATH (true on most Linux servers with poppler-utils installed;
    // a fresh Windows install needs the full path here until PATH is
    // refreshed).
    'pdftoppm_binary' => env('PDF_OCR_PDFTOPPM_BINARY', 'pdftoppm'),

    // Absolute path to tesseract(.exe), or just 'tesseract' if on PATH.
    'tesseract_binary' => env('PDF_OCR_TESSERACT_BINARY', 'tesseract'),

    // Directory containing the .traineddata language files Tesseract
    // needs (ara.traineddata, eng.traineddata, osd.traineddata) -
    // bundled under storage/app/tessdata rather than relying on
    // whatever (if anything) shipped with the system Tesseract install,
    // so the exact language data used is part of this app's own state,
    // not the host machine's.
    'tessdata_dir' => env('PDF_OCR_TESSDATA_DIR', storage_path('app/tessdata')),

    // Tesseract -l value - Arabic AND English together, since this
    // app's real documents mix both on the same page (and even the
    // same line).
    'languages' => env('PDF_OCR_LANGUAGES', 'ara+eng'),

    // Render resolution, in DPI - passed to pdftoppm's -r flag. 300 is
    // the confirmed sweet spot on this app's real documents: high
    // enough for Tesseract to resolve Arabic letter shapes reliably,
    // without the much longer per-page render/OCR time a higher DPI
    // costs.
    'dpi' => env('PDF_OCR_DPI', 300),

    // Hard ceiling on pages OCR'd per sync. OCR costs real seconds PER
    // PAGE, unlike PdfTextImport's single whole-document parse, so this
    // is deliberately far lower than SyncSourcesAction::MAX_PAGES
    // (2000) - a source misconfigured with ocr=true on a huge document
    // would otherwise make one sync run for a very long time.
    'max_pages' => env('PDF_OCR_MAX_PAGES', 100),

    // Per-process timeouts, in seconds - render (pdftoppm, whole
    // document in one call) and OCR (tesseract, one call per page).
    'render_timeout' => env('PDF_OCR_RENDER_TIMEOUT', 120),
    'ocr_timeout' => env('PDF_OCR_TIMEOUT', 60),

];
