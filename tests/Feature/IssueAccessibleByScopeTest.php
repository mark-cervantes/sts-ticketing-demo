<?php

namespace Tests\Feature;

use App\Models\Issue;
use App\Models\IssueShare;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * SRS §8.2 I-18 — Issue::scopeAccessibleBy contract.
 *
 * The scope must return issues where the given user is the owner,
 * OR has a share row, OR the issue is public.
 * It must exclude private issues the user has no relationship to,
 * and soft-deleted issues.
 *
 * Coder must add scopeAccessibleBy() to app/Models/Issue.php to make these pass.
 */
class IssueAccessibleByScopeTest extends TestCase
{
    use RefreshDatabase;

    /** SRS §8.2 I-18: scope returns issues owned by the user. */
    public function test_scope_returns_owned_issues(): void
    {
        $user = User::factory()->create();
        $issue = Issue::factory()->for($user)->private()->create();

        $accessible = Issue::accessibleBy($user)->get();

        $this->assertTrue($accessible->contains($issue));
    }

    /** SRS §8.2 I-18: scope returns issues shared with the user (any permission). */
    public function test_scope_returns_shared_issues(): void
    {
        $owner = User::factory()->create();
        $viewer = User::factory()->create();
        $issue = Issue::factory()->for($owner)->private()->create();
        IssueShare::factory()->for($issue)->for($viewer, 'user')->view()->create();

        $accessible = Issue::accessibleBy($viewer)->get();

        $this->assertTrue($accessible->contains($issue));
    }

    /** SRS §8.2 I-18: scope returns public issues for any authenticated user. */
    public function test_scope_returns_public_issues(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $issue = Issue::factory()->for($owner)->public()->create();

        $accessible = Issue::accessibleBy($other)->get();

        $this->assertTrue($accessible->contains($issue));
    }

    /** SRS §8.2 I-06: scope excludes private issues the user has no relationship to. */
    public function test_scope_excludes_private_unshared_other_user_issues(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $issue = Issue::factory()->for($owner)->private()->create();

        $accessible = Issue::accessibleBy($other)->get();

        $this->assertFalse($accessible->contains($issue));
    }

    /** SRS §8.2: scope excludes soft-deleted issues automatically (SoftDeletes global scope). */
    public function test_scope_excludes_soft_deleted_issues(): void
    {
        $user = User::factory()->create();
        $issue = Issue::factory()->for($user)->create();

        $issue->delete(); // soft delete

        $accessible = Issue::accessibleBy($user)->get();

        $this->assertFalse($accessible->contains('id', $issue->id));
    }

    /**
     * SRS §8.2 I-18: scope returns distinct results when user is both owner and shared.
     *
     * This edge case should not occur in normal business flows (owner cannot be
     * shared on their own issue), but the scope must not double-count via OR semantics.
     */
    public function test_scope_returns_distinct_results_when_user_is_owner_and_shared(): void
    {
        $user = User::factory()->create();
        $issue = Issue::factory()->for($user)->private()->create();

        // Simulate a degenerate share row where the owner is also the shared user.
        IssueShare::factory()->for($issue)->for($user, 'user')->view()->create();

        $accessible = Issue::accessibleBy($user)->get();

        // The issue must appear exactly once — not duplicated.
        $this->assertSame(1, $accessible->where('id', $issue->id)->count());
    }
}
