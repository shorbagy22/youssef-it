<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;

/**
 * Placeholder for the chat feature.
 *
 * Renders a "coming soon" view only. SharePoint document retrieval, text
 * extraction, prompt building, and the Ollama call are a later
 * milestone - explicitly out of scope for Phase 1.
 */
final class ChatController extends Controller
{
    /**
     * Display the chat placeholder page.
     */
    public function __invoke(): View
    {
        return view('chat.index');
    }
}
