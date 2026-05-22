<?php

namespace Tests\Unit\Enums;

use App\Enums\SummaryStatus;
use PHPUnit\Framework\TestCase;

/**
 * SPEC §4.2
 * SummaryStatus enum: case ordering matches spec (pending, processing, ready, failed).
 * Task brief omits this enum but SPEC §4.2 specifies it — added per QA mandate.
 */
class SummaryStatusTest extends TestCase
{
    public function test_summary_status_values_match_spec(): void
    {
        $cases = SummaryStatus::cases();

        $this->assertCount(4, $cases);
        $this->assertSame('pending', $cases[0]->value);
        $this->assertSame('processing', $cases[1]->value);
        $this->assertSame('ready', $cases[2]->value);
        $this->assertSame('failed', $cases[3]->value);
    }
}
