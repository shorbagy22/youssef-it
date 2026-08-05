<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;

/**
 * Placeholder for the application settings feature.
 *
 * Renders a "coming soon" view only. Configuring SharePoint/Ollama
 * connections from the UI is a later milestone - explicitly out of scope
 * for Phase 1.
 */
final class SettingsController extends Controller
{
    /**
     * Display the settings placeholder page.
     */
    public function __invoke(): View
    {
        return view('settings.index');
    }
}
