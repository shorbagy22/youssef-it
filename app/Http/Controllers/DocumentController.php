<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;

/**
 * Placeholder for the documents feature.
 *
 * Renders a "coming soon" view only. Listing/browsing SharePoint
 * documents is a later milestone - explicitly out of scope for Phase 1.
 */
final class DocumentController extends Controller
{
    /**
     * Display the documents placeholder page.
     */
    public function __invoke(): View
    {
        return view('documents.index');
    }
}
