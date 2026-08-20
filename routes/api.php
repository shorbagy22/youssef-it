<?php

declare(strict_types=1);

use App\Http\Controllers\Api\AreaScrapDefectCountController;
use App\Http\Controllers\Api\ChatController;
use App\Http\Controllers\Api\DataAnalysisController;
use App\Http\Controllers\Api\DataReadabilityController;
use App\Http\Controllers\Api\DefectAnalysisController;
use App\Http\Controllers\Api\DefectQueryController;
use App\Http\Controllers\Api\PdfQaController;
use App\Http\Controllers\Api\SourceController;
use Illuminate\Support\Facades\Route;

Route::post('/chat', ChatController::class)
    ->middleware('throttle:20,1')
    ->name('api.chat');

Route::post('/defects/analyze', DefectAnalysisController::class)
    ->middleware('throttle:20,1')
    ->name('api.defects.analyze');

Route::post('/defects/query', DefectQueryController::class)
    ->middleware('throttle:20,1')
    ->name('api.defects.query');

Route::post('/defects/area-scrap-count', AreaScrapDefectCountController::class)
    ->middleware('throttle:20,1')
    ->name('api.defects.area-scrap-count');

Route::post('/data/check-readability', DataReadabilityController::class)
    ->middleware('throttle:20,1')
    ->name('api.data.check-readability');

Route::post('/data/analyze', DataAnalysisController::class)
    ->middleware('throttle:20,1')
    ->name('api.data.analyze');

Route::post('/pdf/ask', PdfQaController::class)
    ->middleware('throttle:20,1')
    ->name('api.pdf.ask');

Route::get('/sources', [SourceController::class, 'index'])->name('api.sources.index');
Route::post('/sources', [SourceController::class, 'store'])->name('api.sources.store');
