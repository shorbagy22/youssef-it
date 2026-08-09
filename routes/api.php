<?php

declare(strict_types=1);

use App\Http\Controllers\Api\ChatController;
use App\Http\Controllers\Api\SourceController;
use Illuminate\Support\Facades\Route;

Route::post('/chat', ChatController::class)
    ->middleware('throttle:20,1')
    ->name('api.chat');

Route::get('/sources', [SourceController::class, 'index'])->name('api.sources.index');
Route::post('/sources', [SourceController::class, 'store'])->name('api.sources.store');
