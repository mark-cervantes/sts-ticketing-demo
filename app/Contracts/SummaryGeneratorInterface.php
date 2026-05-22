<?php

namespace App\Contracts;

use App\Exceptions\SummaryGenerationException;
use App\Models\Issue;
use App\Services\Summary\SummaryResult;

/**
 * Contract for all AI summary generation drivers.
 *
 * @see SPEC §7.1 / SRS §7.2
 */
interface SummaryGeneratorInterface
{
    /**
     * Generate a summary for the given issue.
     *
     * @throws SummaryGenerationException
     */
    public function generate(Issue $issue): SummaryResult;
}
