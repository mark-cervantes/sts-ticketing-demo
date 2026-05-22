<?php

namespace Tests\Unit\Enums;

use App\Enums\Status;
use PHPUnit\Framework\TestCase;

/**
 * SPEC §4.2 / BR-01
 * Status enum: case ordering and values match spec.
 */
class StatusTest extends TestCase
{
    public function test_status_values_match_spec(): void
    {
        $cases = Status::cases();

        $this->assertCount(3, $cases);
        $this->assertSame('open', $cases[0]->value);
        $this->assertSame('in_progress', $cases[1]->value);
        $this->assertSame('resolved', $cases[2]->value);
    }
}
