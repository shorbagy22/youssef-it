<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\DepartmentController as AdminDepartmentController;
use App\Http\Controllers\Admin\SourceController as AdminSourceController;
use App\Http\Controllers\ChatController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\SettingsController;
use App\Models\Department;
use Illuminate\Support\Facades\Route;

// Public, unauthenticated pages - a factory-floor dashboard and per-department
// chat UI backed by the direct-Ollama /api/chat pipeline. Separate from the
// authenticated /dashboard and /chat below, which use a different backend.
Route::get('/', function () {
    return view('public-dashboard', [
        'departments' => Department::all(),
    ]);
})->name('public.dashboard');

Route::get('/chat/{slug}', function (string $slug) {
    $department = Department::where('slug', $slug)->firstOrFail();

    return view('chat', ['department' => $department->slug]);
})->name('public.chat');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/dashboard', DashboardController::class)->name('dashboard');
    Route::get('/chat', [ChatController::class, 'index'])->name('chat');
    Route::post('/chat/send', [ChatController::class, 'send'])->name('chat.send');
    Route::get('/documents', DocumentController::class)->name('documents');
    Route::get('/settings', SettingsController::class)->name('settings');
});

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

// Admin UI for managing Sources and Departments - a Blade counterpart to
// POST /api/sources, not a replacement. Auth-gated like the rest of the
// app; unrelated to the public dashboard/chat pages above, though the
// departments managed here drive what those pages render.
Route::middleware(['auth', 'verified'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/sources', [AdminSourceController::class, 'index'])->name('sources.index');
    Route::get('/sources/create', [AdminSourceController::class, 'create'])->name('sources.create');
    Route::post('/sources', [AdminSourceController::class, 'store'])->name('sources.store');
    Route::post('/sources/sync', [AdminSourceController::class, 'sync'])->name('sources.sync');
    Route::get('/sources/{source}/edit', [AdminSourceController::class, 'edit'])->name('sources.edit');
    Route::put('/sources/{source}', [AdminSourceController::class, 'update'])->name('sources.update');
    Route::delete('/sources/{source}', [AdminSourceController::class, 'destroy'])->name('sources.destroy');

    Route::get('/departments', [AdminDepartmentController::class, 'index'])->name('departments.index');
    Route::get('/departments/create', [AdminDepartmentController::class, 'create'])->name('departments.create');
    Route::post('/departments', [AdminDepartmentController::class, 'store'])->name('departments.store');
    Route::delete('/departments/{department}', [AdminDepartmentController::class, 'destroy'])->name('departments.destroy');
});

require __DIR__.'/auth.php';
