<?php

use App\Http\Controllers\CategoryController;
use App\Http\Controllers\CommentController;
use App\Http\Controllers\IssueController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->apiResource('issues', IssueController::class);

Route::middleware('auth')->post('issues/{issue}/comments', [CommentController::class, 'store']);

Route::middleware('auth')->apiResource('categories', CategoryController::class)->only(['index', 'store', 'destroy']);
