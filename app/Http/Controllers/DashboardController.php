<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Dashboard\GetSystemStatusAction;
use Illuminate\Contracts\View\View;

/**
 * Renders the authenticated dashboard: a welcome message, the current
 * user's info, the application version, and system status cards.
 *
 * Kept thin per Clean Architecture - all business logic (building the
 * status snapshot) lives in GetSystemStatusAction, injected here rather
 * than constructed, so this controller has no knowledge of how statuses
 * are determined.
 */
final class DashboardController extends Controller
{
    public function __construct(
        private readonly GetSystemStatusAction $getSystemStatus,
    ) {}

    /**
     * Display the dashboard.
     */
    public function __invoke(): View
    {
        return view('dashboard', [
            'systemStatus' => $this->getSystemStatus->handle(),
            'appVersion' => config('chatbot.version'),
        ]);
    }
}
