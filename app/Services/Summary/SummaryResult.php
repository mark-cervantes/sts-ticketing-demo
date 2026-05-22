<?php

namespace App\Services\Summary;

/**
 * Immutable value object returned by every summary driver.
 *
 * @see SPEC §7.1
 */
final readonly class SummaryResult
{
    public function __construct(
        public string $summary,
        public string $suggestedNextAction,
        public string $driver,
    ) {}
}
