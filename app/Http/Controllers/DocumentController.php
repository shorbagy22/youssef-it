<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;

/**
 * Placeholder for the documents feature.
 *
 * Renders a "coming soon" view only. Document storage and retrieval are
 * owned entirely by the company's AI service now - Laravel has no
 * document integration of its own.
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
