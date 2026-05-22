<?php

namespace Tests\Unit\Enums;

use App\Enums\Permission;
use PHPUnit\Framework\TestCase;

/**
 * SRS §3.2 / SPEC §4.5
 * Permission enum: ladderized canComment() / canEdit() contract and case ordering.
 * Ladder: view < comment < edit
 */
class PermissionTest extends TestCase
{
    /** @test */
    public function test_view_cannot_comment_or_edit(): void
    {
        $this->assertFalse(Permission::View->canComment());
        $this->assertFalse(Permission::View->canEdit());
    }

    /** @test */
    public function test_comment_can_comment_but_not_edit(): void
    {
        $this->assertTrue(Permission::Comment->canComment());
        $this->assertFalse(Permission::Comment->canEdit());
    }

    /** @test */
    public function test_edit_can_both(): void
    {
        $this->assertTrue(Permission::Edit->canComment());
        $this->assertTrue(Permission::Edit->canEdit());
    }

    /**
     * Bonus QA: enum case ordering matches spec (view, comment, edit).
     */
    public function test_permission_values_match_spec(): void
    {
        $cases = Permission::cases();

        $this->assertCount(3, $cases);
        $this->assertSame('view', $cases[0]->value);
        $this->assertSame('comment', $cases[1]->value);
        $this->assertSame('edit', $cases[2]->value);
    }
}
