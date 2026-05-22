<?php

namespace Tests\Unit\Enums;

use App\Enums\Visibility;
use PHPUnit\Framework\TestCase;

/**
 * SPEC §4.2 / BR-01
 * Visibility enum: case ordering and values match spec.
 */
class VisibilityTest extends TestCase
{
    public function test_visibility_values_match_spec(): void
    {
        $cases = Visibility::cases();

        $this->assertCount(2, $cases);
        $this->assertSame('private', $cases[0]->value);
        $this->assertSame('public', $cases[1]->value);
    }
}
