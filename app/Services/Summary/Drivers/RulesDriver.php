<?php

namespace App\Services\Summary\Drivers;

use App\Contracts\SummaryGeneratorInterface;
use App\Enums\Priority;
use App\Models\Issue;
use App\Services\Summary\SummaryResult;

/**
 * Deterministic rules-based summary driver.
 *
 * Uses a category + priority matrix to produce domain-specific summaries
 * without any external API calls. Serves as the guaranteed fallback.
 *
 * @see SRS §7.4
 */
class RulesDriver implements SummaryGeneratorInterface
{
    /**
     * Generate a summary using the category/priority rules matrix.
     */
    public function generate(Issue $issue): SummaryResult
    {
        $categoryName = $issue->category?->name ?? 'general';
        $priority = $issue->priority instanceof Priority
            ? $issue->priority
            : Priority::tryFrom((string) $issue->priority) ?? Priority::Medium;

        $leadSentence = $this->extractLeadSentence($issue->description ?? '');
        $summary = $this->buildSummary($categoryName, $issue->title ?? '', $leadSentence);
        $action = $this->buildAction($categoryName, $priority);

        return new SummaryResult(
            summary: $summary,
            suggestedNextAction: $action,
            driver: 'rules',
        );
    }

    /**
     * Extract the first meaningful sentence from a description.
     */
    private function extractLeadSentence(string $description): string
    {
        $description = trim($description);

        if ($description === '') {
            return '';
        }

        // Split on sentence boundaries; take the first non-empty sentence.
        $sentences = preg_split('/(?<=[.!?])\s+/', $description, 2);

        return trim($sentences[0] ?? $description);
    }

    /**
     * Build a category-aware summary string.
     */
    private function buildSummary(string $category, string $title, string $lead): string
    {
        $titlePart = $title !== '' ? "\"$title\"" : 'the reported issue';
        $leadPart = $lead !== '' ? " $lead" : '';

        return match ($category) {
            'billing' => "A billing issue has been reported: $titlePart.$leadPart Billing records and payment history should be reviewed.",
            'technical' => "A technical issue has been reported: $titlePart.$leadPart The system or integration behaviour requires investigation.",
            'account' => "An account-related issue has been reported: $titlePart.$leadPart User account data and permissions should be inspected.",
            'bug' => "A software bug has been reported: $titlePart.$leadPart The defect needs to be reproduced and root-caused.",
            'feature-request' => "A feature request has been submitted: $titlePart.$leadPart The request should be reviewed against the product roadmap.",
            'general' => "A general issue has been reported: $titlePart.$leadPart The issue requires triage and classification.",
            default => "An issue has been reported under category '$category': $titlePart.$leadPart The issue requires review.",
        };
    }

    /**
     * Build a priority-aware, category-informed action suggestion.
     */
    private function buildAction(string $category, Priority $priority): string
    {
        $urgencyPrefix = match ($priority) {
            Priority::Critical => 'Immediately escalate to the on-call team and',
            Priority::High => 'Prioritise this ticket and',
            Priority::Medium => 'Assign to the relevant team and',
            Priority::Low => 'Schedule for the next available slot and',
        };

        $categoryAction = match ($category) {
            'billing' => 'verify the customer\'s billing records, recent transactions, and invoice delivery logs.',
            'technical' => 'reproduce the issue in a staging environment and inspect application and infrastructure logs.',
            'account' => 'audit the user\'s account configuration, role assignments, and recent activity log.',
            'bug' => 'capture a reproducible test case, isolate the regression point, and open a bug-fix branch.',
            'feature-request' => 'log the request in the product backlog with stakeholder context and usage impact.',
            'general' => 'triage the issue, assign to the appropriate team, and set an initial response SLA.',
            default => 'review the issue details and route to the most appropriate team for resolution.',
        };

        return "$urgencyPrefix $categoryAction";
    }
}
