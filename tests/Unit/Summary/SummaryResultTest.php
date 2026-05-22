<?php

namespace Tests\Unit\Summary;

use App\Services\Summary\SummaryResult;
use Tests\TestCase;

/**
 * SRS §7.1 — SummaryResult value object: immutability and field access.
 *
 * No DB, no HTTP, no queue — pure in-memory construction.
 */
class SummaryResultTest extends TestCase
{
    /** SRS §7.1: SummaryResult exposes summary string via public property. */
    public function test_summary_property_is_accessible(): void
    {
        $result = new SummaryResult(
            summary: 'Login fails for users with special characters in their password.',
            suggestedNextAction: 'Update the auth middleware to skip pre-sanitization before hashing.',
            driver: 'rules',
        );

        $this->assertSame('Login fails for users with special characters in their password.', $result->summary);
    }

    /** SRS §7.1: SummaryResult exposes suggestedNextAction string via public property. */
    public function test_suggested_next_action_property_is_accessible(): void
    {
        $result = new SummaryResult(
            summary: 'Payment gateway returns 503 during peak hours.',
            suggestedNextAction: 'Increase timeout to 60s and add exponential backoff retry.',
            driver: 'llm',
        );

        $this->assertSame('Increase timeout to 60s and add exponential backoff retry.', $result->suggestedNextAction);
    }

    /** SRS §7.1: SummaryResult exposes driver string via public property. */
    public function test_driver_property_is_accessible(): void
    {
        $result = new SummaryResult(
            summary: 'A billing issue was reported.',
            suggestedNextAction: 'Review billing logs.',
            driver: 'llm',
        );

        $this->assertSame('llm', $result->driver);
    }

    /** SRS §7.1: SummaryResult is immutable — writing to property throws Error. */
    public function test_summary_result_is_immutable(): void
    {
        $result = new SummaryResult(
            summary: 'Original summary text.',
            suggestedNextAction: 'Original action text.',
            driver: 'rules',
        );

        $this->expectException(\Error::class);

        // @phpstan-ignore-next-line
        $result->summary = 'Mutated summary text.';
    }

    /** SRS §7.1: SummaryResult suggestedNextAction is immutable. */
    public function test_suggested_next_action_is_immutable(): void
    {
        $result = new SummaryResult(
            summary: 'Summary content.',
            suggestedNextAction: 'Original action.',
            driver: 'rules',
        );

        $this->expectException(\Error::class);

        // @phpstan-ignore-next-line
        $result->suggestedNextAction = 'Mutated action.';
    }
}
