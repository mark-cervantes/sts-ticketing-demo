<?php

use App\Http\Controllers\IssueController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->apiResource('issues', IssueController::class);
