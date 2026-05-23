<?php

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
