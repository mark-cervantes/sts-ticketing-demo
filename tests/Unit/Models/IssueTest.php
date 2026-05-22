<?php

namespace Tests\Unit\Models;

use App\Enums\Priority;
use App\Models\Issue;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * SPEC §4.2 / ADR-005 / BR-03
 * Issue model: computeNeedsAttention() pure logic + saving event integration.
 */
class IssueTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Hermetic config — isolate from environment overrides.
        Config::set('issues.attention_threshold_minutes', 60);
    }

    // -----------------------------------------------------------------------
    // computeNeedsAttention() — pure static method, no DB needed
    // -----------------------------------------------------------------------

    /**
     * High priority always triggers needs_attention regardless of deadline.
     */
    public function test_compute_needs_attention_high_priority_true(): void
    {
        $result = Issue::computeNeedsAttention(Priority::High, null);

        $this->assertTrue($result);
    }

    /**
     * Critical priority always triggers needs_attention regardless of deadline.
     */
    public function test_compute_needs_attention_critical_priority_true(): void
    {
        $result = Issue::computeNeedsAttention(Priority::Critical, null);

        $this->assertTrue($result);
    }

    /**
     * Low priority with no deadline → false (neither priority nor deadline signal fires).
     */
    public function test_compute_needs_attention_low_priority_no_deadline_false(): void
    {
        $result = Issue::computeNeedsAttention(Priority::Low, null);

        $this->assertFalse($result);
    }

    /**
     * Low priority + deadline within the threshold window (now+30min < threshold 60min) → true.
     * The deadline signal overrides the low-priority signal.
     */
    public function test_compute_needs_attention_low_priority_deadline_within_threshold_true(): void
    {
        $deadline = CarbonImmutable::now()->addMinutes(30);

        $result = Issue::computeNeedsAttention(Priority::Low, $deadline);

        $this->assertTrue($result);
    }

    /**
     * Low priority + deadline far in the future (now+1day > threshold 60min) → false.
     */
    public function test_compute_needs_attention_low_priority_deadline_far_future_false(): void
    {
        $deadline = CarbonImmutable::now()->addDay();

        $result = Issue::computeNeedsAttention(Priority::Low, $deadline);

        $this->assertFalse($result);
    }

    /**
     * Medium priority with no deadline → false.
     * Bonus QA: covers the Medium case explicitly (not just Low).
     */
    public function test_compute_needs_attention_medium_priority_no_deadline_false(): void
    {
        $result = Issue::computeNeedsAttention(Priority::Medium, null);

        $this->assertFalse($result);
    }

    /**
     * Deadline exactly at the threshold boundary (now+60min == threshold 60min) → true.
     * lte() is inclusive: deadline ≤ now+threshold → needs attention.
     */
    public function test_compute_needs_attention_deadline_exactly_at_threshold_true(): void
    {
        $deadline = CarbonImmutable::now()->addMinutes(60);

        $result = Issue::computeNeedsAttention(Priority::Low, $deadline);

        $this->assertTrue($result);
    }

    // -----------------------------------------------------------------------
    // saving event — DB required
    // -----------------------------------------------------------------------

    /**
     * The saving event fires on create and sets needs_attention automatically.
     * High priority → column must be true after persist and fresh reload.
     */
    public function test_saving_event_sets_needs_attention_column(): void
    {
        $issue = Issue::factory()->create([
            'priority' => Priority::High,
            'deadline_at' => null,
        ]);

        $this->assertTrue($issue->fresh()->needs_attention);
    }

    /**
     * Saving event: low priority + no deadline → needs_attention persisted as false.
     */
    public function test_saving_event_sets_needs_attention_false_for_low_priority(): void
    {
        $issue = Issue::factory()->create([
            'priority' => Priority::Low,
            'deadline_at' => null,
        ]);

        $this->assertFalse($issue->fresh()->needs_attention);
    }

    /**
     * Saving event fires on UPDATE too (BR-03: recomputed on every create/update).
     * Start with low priority, update to critical → needs_attention flips to true.
     */
    public function test_saving_event_recomputes_on_update(): void
    {
        $issue = Issue::factory()->create([
            'priority' => Priority::Low,
            'deadline_at' => null,
        ]);

        $this->assertFalse($issue->fresh()->needs_attention);

        $issue->update(['priority' => Priority::Critical]);

        $this->assertTrue($issue->fresh()->needs_attention);
    }
}
