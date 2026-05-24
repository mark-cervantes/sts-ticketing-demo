<?php

use App\Http\Controllers\Api\AiSettingsController;
use App\Http\Controllers\Api\CommentReactionController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\CommentController;
use App\Http\Controllers\IssueController;
use App\Http\Controllers\IssueSseController;
use App\Http\Controllers\ShareController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->apiResource('issues', IssueController::class);

Route::middleware('auth')->post('issues/{issue}/comments', [CommentController::class, 'store']);

Route::middleware('auth')->apiResource('categories', CategoryController::class)->only(['index', 'store', 'destroy']);

Route::middleware('auth')->get('issues/{issue}/stream', IssueSseController::class);

Route::middleware('auth')->apiResource('issues.shares', ShareController::class)->shallow();

// Emoji reactions on comments
Route::middleware('auth')->group(function () {
    Route::post('comments/{comment}/reactions', [CommentReactionController::class, 'toggle']);
    Route::get('comments/{comment}/reactions', [CommentReactionController::class, 'index']);
});

// AI — regenerate summary + triage suggestions
Route::middleware('auth')->group(function () {
    Route::post('issues/{issue}/regenerate-summary', [IssueController::class, 'regenerateSummary']);
    // triage-suggest must be declared BEFORE the apiResource wildcard to avoid
    // the {issue} segment swallowing "triage-suggest" as an issue ID.
    Route::post('issues/triage-suggest', [IssueController::class, 'triageSuggest']);
});

// AI settings
Route::middleware('auth')->group(function () {
    Route::get('settings/ai', [AiSettingsController::class, 'show']);
    Route::put('settings/ai', [AiSettingsController::class, 'update']);
    Route::post('settings/ai/test', [AiSettingsController::class, 'test']);
    Route::get('settings/ai/models', [AiSettingsController::class, 'models']);
    Route::get('settings/ai/presets', [AiSettingsController::class, 'presets']);
});
