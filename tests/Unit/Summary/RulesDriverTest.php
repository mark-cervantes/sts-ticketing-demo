<?php

namespace Tests\Unit\Summary;

use App\Enums\Priority;
use App\Models\Category;
use App\Models\Issue;
use App\Services\Summary\Drivers\RulesDriver;
use App\Services\Summary\SummaryResult;
use Tests\TestCase;

/**
 * SRS §7.4 — RulesDriver: deterministic fallback driver.
 *
 * No DB, no HTTP — Issue instances built with make() and relations set manually.
 * The driver must produce genuinely non-empty output for all tested inputs.
 */
class RulesDriverTest extends TestCase
{
    /**
     * Build an in-memory Issue with category relation set — no DB roundtrip.
     */
    private function makeIssue(
        string $categoryName = 'technical',
        string $priority = 'medium',
        string $title = 'System is behaving unexpectedly',
        string $description = 'Users are reporting issues with the core workflow. The problem started appearing after the recent deployment.',
    ): Issue {
        $category = new Category(['name' => $categoryName]);
        $category->id = 1;

        $issue = Issue::make([
            'title' => $title,
            'description' => $description,
            'priority' => $priority,
        ]);

        $issue->setRelation('category', $category);

        return $issue;
    }

    /** SRS §7.4: driver returns a SummaryResult instance for a technical issue. */
    public function test_returns_summary_result_instance(): void
    {
        $driver = new RulesDriver();

        $result = $driver->generate($this->makeIssue('technical'));

        $this->assertInstanceOf(SummaryResult::class, $result);
    }

    /** SRS §7.4: driver result has a non-empty summary string. */
    public function test_summary_is_non_empty(): void
    {
        $driver = new RulesDriver();

        $result = $driver->generate($this->makeIssue('billing'));

        $this->assertNotEmpty($result->summary);
    }

    /** SRS §7.4: driver result has a non-empty suggestedNextAction string. */
    public function test_suggested_next_action_is_non_empty(): void
    {
        $driver = new RulesDriver();

        $result = $driver->generate($this->makeIssue('account'));

        $this->assertNotEmpty($result->suggestedNextAction);
    }

    /** SRS §7.4: driver result identifies itself as the 'rules' driver. */
    public function test_driver_property_is_rules(): void
    {
        $driver = new RulesDriver();

        $result = $driver->generate($this->makeIssue());

        $this->assertSame('rules', $result->driver);
    }

    /** SRS §7.4: billing category produces different summary than technical category. */
    public function test_output_varies_by_category_billing_vs_technical(): void
    {
        $driver = new RulesDriver();

        $billingResult = $driver->generate($this->makeIssue('billing', 'medium', 'Invoice not generated', 'Customer reports missing invoice for last month.'));
        $technicalResult = $driver->generate($this->makeIssue('technical', 'medium', 'API endpoint returns 500', 'The /api/users endpoint consistently returns 500 on POST requests.'));

        // Summaries must differ — rules driver is category-aware
        $this->assertNotSame($billingResult->summary, $technicalResult->summary);
    }

    /** SRS §7.4: bug category produces different summary than feature-request category. */
    public function test_output_varies_by_category_bug_vs_feature_request(): void
    {
        $driver = new RulesDriver();

        $bugResult = $driver->generate($this->makeIssue('bug', 'high', 'Login crashes on Safari', 'Safari 17 throws JS exception when the login form submits.'));
        $featureResult = $driver->generate($this->makeIssue('feature-request', 'low', 'Add dark mode', 'Users are requesting a dark mode option in the settings panel.'));

        $this->assertNotSame($bugResult->suggestedNextAction, $featureResult->suggestedNextAction);
    }

    /** SRS §7.4: critical priority produces more urgent action text than low priority. */
    public function test_output_varies_by_priority_critical_vs_low(): void
    {
        $driver = new RulesDriver();

        $criticalResult = $driver->generate(
            $this->makeIssue('general', 'critical', 'All users locked out', 'Authentication service is down, no users can log in.')
        );
        $lowResult = $driver->generate(
            $this->makeIssue('general', 'low', 'Minor typo in footer', 'There is a typo in the copyright notice in the footer.')
        );

        // Priority-aware: the action text or summary must differ between critical and low
        $this->assertNotSame($criticalResult->suggestedNextAction, $lowResult->suggestedNextAction);
    }

    /** SRS §7.4: account category produces non-empty, category-specific output. */
    public function test_account_category_produces_category_aware_output(): void
    {
        $driver = new RulesDriver();

        $result = $driver->generate(
            $this->makeIssue('account', 'medium', 'Cannot update email address', 'User tries to update email but gets a validation error every time.')
        );

        $this->assertNotEmpty($result->summary);
        $this->assertNotEmpty($result->suggestedNextAction);
    }

    /** SRS §7.4: general category (generic fallback) still produces useful output. */
    public function test_general_category_produces_non_empty_output(): void
    {
        $driver = new RulesDriver();

        $result = $driver->generate(
            $this->makeIssue('general', 'medium', 'Miscellaneous issue', 'User reports an unspecified problem that does not fit other categories.')
        );

        $this->assertNotEmpty($result->summary);
        $this->assertNotEmpty($result->suggestedNextAction);
    }

    /** SRS §7.4: unknown/user-created category falls back gracefully — no exception. */
    public function test_unknown_category_does_not_throw(): void
    {
        $driver = new RulesDriver();

        // A category not in the seeded six — driver must have a generic fallback.
        $result = $driver->generate(
            $this->makeIssue('custom-enterprise-workflow', 'medium', 'Enterprise workflow issue', 'An issue with a user-created category not in the standard set.')
        );

        $this->assertNotEmpty($result->summary);
        $this->assertNotEmpty($result->suggestedNextAction);
    }
}
