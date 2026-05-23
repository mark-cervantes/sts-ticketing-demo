<?php

namespace App\Facades;

use App\Services\Summary\SummaryManager;
use Illuminate\Support\Facades\Facade;

/**
 * Facade for the SummaryManager.
 *
 * @see SummaryManager
 *
 * @method static \App\Services\Summary\SummaryResult generate(\App\Models\Issue $issue)
 * @method static \App\Contracts\SummaryGeneratorInterface driver(?string $driver = null)
 */
class Summary extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return SummaryManager::class;
    }
}
