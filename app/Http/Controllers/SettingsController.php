<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;

/**
 * Placeholder for the application settings feature.
 *
 * Renders a "coming soon" view only.
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
