<?php

namespace Tests\Unit\Enums;

use App\Enums\Priority;
use PHPUnit\Framework\TestCase;

/**
 * SRS §3.2 / SPEC §4.2 / ADR-005
 * Priority enum: needsAttention() contract and case ordering.
 */
class PriorityTest extends TestCase
{
    /** @test */
    public function test_needs_attention_returns_true_for_high_and_critical(): void
    {
        $this->assertTrue(Priority::High->needsAttention());
        $this->assertTrue(Priority::Critical->needsAttention());
    }

    /** @test */
    public function test_needs_attention_returns_false_for_low_and_medium(): void
    {
        $this->assertFalse(Priority::Low->needsAttention());
        $this->assertFalse(Priority::Medium->needsAttention());
    }

    /**
     * Bonus QA: enum case ordering matches spec (low, medium, high, critical).
     * Guards against accidental reordering that could break comparisons.
     */
    public function test_priority_values_match_spec(): void
    {
        $cases = Priority::cases();

        $this->assertCount(4, $cases);
        $this->assertSame('low', $cases[0]->value);
        $this->assertSame('medium', $cases[1]->value);
        $this->assertSame('high', $cases[2]->value);
        $this->assertSame('critical', $cases[3]->value);
    }
}
