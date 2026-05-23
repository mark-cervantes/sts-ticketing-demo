<?php

namespace Tests\Feature;

use App\Enums\Permission;
use App\Models\Issue;
use App\Models\IssueShare;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Share API — Feature & Integration Tests.
 *
 * SPEC §4.5 / ADR-007 / task 04.01.00
 *
 * Covers:
 *   GET    /api/issues/{issue}/shares       — index (owner only)
 *   POST   /api/issues/{issue}/shares       — store (upsert, self-share guard)
 *   PATCH  /api/shares/{share}              — update permission
 *   DELETE /api/shares/{share}              — destroy
 *
 * Authorization:
 *   - Non-owner gets 403 on all mutations and index
 *
 * Validation:
 *   - Self-share → 422
 *   - Unknown email → 422
 *   - Invalid permission → 422
 *
 * Upsert:
 *   - Sharing same user again updates permission, no duplicate row
 *
 * Visibility integration:
 *   - Shared issue appears in recipient's scopeAccessibleBy results
 */
class ShareControllerTest extends TestCase
{
    use RefreshDatabase;

    // =========================================================================
    // GET /api/issues/{issue}/shares — index
    // =========================================================================

    /** Owner can list shares for their issue. */
    public function test_owner_can_list_shares(): void
    {
        $owner = User::factory()->create();
        $issue = Issue::factory()->for($owner)->create();
        $sharedUser = User::factory()->create();
        IssueShare::factory()->for($issue)->for($sharedUser, 'user')->view()->create();

        $response = $this->actingAs($owner)->getJson("/api/issues/{$issue->id}/shares");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    '*' => ['id', 'permission', 'created_at', 'user' => ['id', 'name', 'email']],
                ],
            ])
            ->assertJsonCount(1, 'data');
    }

    /** Index returns correct permission value and user details. */
    public function test_index_returns_correct_share_data(): void
    {
        $owner = User::factory()->create();
        $issue = Issue::factory()->for($owner)->create();
        $sharedUser = User::factory()->create(['email' => 'shared@example.com']);
        IssueShare::factory()->for($issue)->for($sharedUser, 'user')->comment()->create();

        $response = $this->actingAs($owner)->getJson("/api/issues/{$issue->id}/shares");

        $response->assertStatus(200)
            ->assertJsonPath('data.0.permission', 'comment')
            ->assertJsonPath('data.0.user.email', 'shared@example.com');
    }

    /** Non-owner cannot list shares (403). */
    public function test_non_owner_cannot_list_shares(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $issue = Issue::factory()->for($owner)->create();

        $this->actingAs($other)->getJson("/api/issues/{$issue->id}/shares")
            ->assertStatus(403);
    }

    /** Unauthenticated request returns 401. */
    public function test_unauthenticated_cannot_list_shares(): void
    {
        $owner = User::factory()->create();
        $issue = Issue::factory()->for($owner)->create();

        $this->getJson("/api/issues/{$issue->id}/shares")->assertStatus(401);
    }

    // =========================================================================
    // POST /api/issues/{issue}/shares — store (create)
    // =========================================================================

    /** Owner can share their issue with another user (201). */
    public function test_owner_can_share_issue_with_user(): void
    {
        $owner = User::factory()->create();
        $issue = Issue::factory()->for($owner)->create();
        $target = User::factory()->create(['email' => 'target@example.com']);

        $response = $this->actingAs($owner)->postJson("/api/issues/{$issue->id}/shares", [
            'email' => 'target@example.com',
            'permission' => 'view',
        ]);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'data' => ['id', 'permission', 'created_at', 'user' => ['id', 'name', 'email']],
            ])
            ->assertJsonPath('data.permission', 'view')
            ->assertJsonPath('data.user.email', 'target@example.com');
    }

    /** Share is persisted to the database. */
    public function test_share_is_saved_to_database(): void
    {
        $owner = User::factory()->create();
        $issue = Issue::factory()->for($owner)->create();
        $target = User::factory()->create(['email' => 'saved@example.com']);

        $this->actingAs($owner)->postJson("/api/issues/{$issue->id}/shares", [
            'email' => 'saved@example.com',
            'permission' => 'edit',
        ])->assertStatus(201);

        $this->assertDatabaseHas('issue_shares', [
            'issue_id' => $issue->id,
            'user_id' => $target->id,
            'permission' => 'edit',
        ]);
    }

    /** All three permission levels are accepted. */
    public function test_store_accepts_all_permission_levels(): void
    {
        $owner = User::factory()->create();
        $issue = Issue::factory()->for($owner)->create();

        foreach (['view', 'comment', 'edit'] as $permission) {
            $target = User::factory()->create();

            $this->actingAs($owner)->postJson("/api/issues/{$issue->id}/shares", [
                'email' => $target->email,
                'permission' => $permission,
            ])->assertStatus(201)
                ->assertJsonPath('data.permission', $permission);
        }
    }

    /** Non-owner cannot share another user's issue (403). */
    public function test_non_owner_cannot_share_issue(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $target = User::factory()->create();
        $issue = Issue::factory()->for($owner)->create();

        $this->actingAs($other)->postJson("/api/issues/{$issue->id}/shares", [
            'email' => $target->email,
            'permission' => 'view',
        ])->assertStatus(403);
    }

    /** Unauthenticated request returns 401. */
    public function test_unauthenticated_cannot_share_issue(): void
    {
        $owner = User::factory()->create();
        $issue = Issue::factory()->for($owner)->create();
        $target = User::factory()->create();

        $this->postJson("/api/issues/{$issue->id}/shares", [
            'email' => $target->email,
            'permission' => 'view',
        ])->assertStatus(401);
    }

    // =========================================================================
    // POST — Upsert behavior
    // =========================================================================

    /** Sharing same user again updates permission, does not create a duplicate row. */
    public function test_sharing_same_user_again_upserts_permission(): void
    {
        $owner = User::factory()->create();
        $issue = Issue::factory()->for($owner)->create();
        $target = User::factory()->create(['email' => 'upsert@example.com']);

        // Initial share
        $this->actingAs($owner)->postJson("/api/issues/{$issue->id}/shares", [
            'email' => 'upsert@example.com',
            'permission' => 'view',
        ])->assertStatus(201);

        // Upsert with new permission
        $this->actingAs($owner)->postJson("/api/issues/{$issue->id}/shares", [
            'email' => 'upsert@example.com',
            'permission' => 'edit',
        ])->assertStatus(201)
            ->assertJsonPath('data.permission', 'edit');

        // Exactly one share row in DB
        $this->assertDatabaseCount('issue_shares', 1);

        $this->assertDatabaseHas('issue_shares', [
            'issue_id' => $issue->id,
            'user_id' => $target->id,
            'permission' => 'edit',
        ]);
    }

    // =========================================================================
    // POST — Validation: self-share prevention (SPEC §4.5)
    // =========================================================================

    /** Cannot share an issue with yourself (422). */
    public function test_self_share_is_rejected_with_422(): void
    {
        $owner = User::factory()->create(['email' => 'owner@example.com']);
        $issue = Issue::factory()->for($owner)->create();

        $this->actingAs($owner)->postJson("/api/issues/{$issue->id}/shares", [
            'email' => 'owner@example.com',
            'permission' => 'view',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }

    // =========================================================================
    // POST — Validation: unknown email
    // =========================================================================

    /** Sharing with an unregistered email returns 422 (not 404). */
    public function test_sharing_with_unregistered_email_returns_422(): void
    {
        $owner = User::factory()->create();
        $issue = Issue::factory()->for($owner)->create();

        $this->actingAs($owner)->postJson("/api/issues/{$issue->id}/shares", [
            'email' => 'nobody@unknown.example',
            'permission' => 'view',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }

    // =========================================================================
    // POST — Validation: invalid permission
    // =========================================================================

    /** Invalid permission value returns 422. */
    public function test_invalid_permission_returns_422_on_store(): void
    {
        $owner = User::factory()->create();
        $issue = Issue::factory()->for($owner)->create();
        $target = User::factory()->create();

        $this->actingAs($owner)->postJson("/api/issues/{$issue->id}/shares", [
            'email' => $target->email,
            'permission' => 'superadmin',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['permission']);
    }

    /** Missing email returns 422. */
    public function test_missing_email_returns_422_on_store(): void
    {
        $owner = User::factory()->create();
        $issue = Issue::factory()->for($owner)->create();

        $this->actingAs($owner)->postJson("/api/issues/{$issue->id}/shares", [
            'permission' => 'view',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }

    /** Missing permission returns 422. */
    public function test_missing_permission_returns_422_on_store(): void
    {
        $owner = User::factory()->create();
        $issue = Issue::factory()->for($owner)->create();
        $target = User::factory()->create();

        $this->actingAs($owner)->postJson("/api/issues/{$issue->id}/shares", [
            'email' => $target->email,
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['permission']);
    }

    // =========================================================================
    // PATCH /api/shares/{share} — update
    // =========================================================================

    /** Owner can update a share's permission (200). */
    public function test_owner_can_update_share_permission(): void
    {
        $owner = User::factory()->create();
        $issue = Issue::factory()->for($owner)->create();
        $sharedUser = User::factory()->create();
        $share = IssueShare::factory()->for($issue)->for($sharedUser, 'user')->view()->create();

        $response = $this->actingAs($owner)->patchJson("/api/shares/{$share->id}", [
            'permission' => 'edit',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.permission', 'edit');

        $this->assertDatabaseHas('issue_shares', [
            'id' => $share->id,
            'permission' => 'edit',
        ]);
    }

    /** Non-owner cannot update a share (403). */
    public function test_non_owner_cannot_update_share(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $issue = Issue::factory()->for($owner)->create();
        $sharedUser = User::factory()->create();
        $share = IssueShare::factory()->for($issue)->for($sharedUser, 'user')->view()->create();

        $this->actingAs($other)->patchJson("/api/shares/{$share->id}", [
            'permission' => 'edit',
        ])->assertStatus(403);
    }

    /** Unauthenticated request returns 401. */
    public function test_unauthenticated_cannot_update_share(): void
    {
        $owner = User::factory()->create();
        $issue = Issue::factory()->for($owner)->create();
        $sharedUser = User::factory()->create();
        $share = IssueShare::factory()->for($issue)->for($sharedUser, 'user')->view()->create();

        $this->patchJson("/api/shares/{$share->id}", [
            'permission' => 'edit',
        ])->assertStatus(401);
    }

    /** Invalid permission on update returns 422. */
    public function test_invalid_permission_returns_422_on_update(): void
    {
        $owner = User::factory()->create();
        $issue = Issue::factory()->for($owner)->create();
        $sharedUser = User::factory()->create();
        $share = IssueShare::factory()->for($issue)->for($sharedUser, 'user')->view()->create();

        $this->actingAs($owner)->patchJson("/api/shares/{$share->id}", [
            'permission' => 'invalid',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['permission']);
    }

    /** Missing permission on update returns 422. */
    public function test_missing_permission_returns_422_on_update(): void
    {
        $owner = User::factory()->create();
        $issue = Issue::factory()->for($owner)->create();
        $sharedUser = User::factory()->create();
        $share = IssueShare::factory()->for($issue)->for($sharedUser, 'user')->view()->create();

        $this->actingAs($owner)->patchJson("/api/shares/{$share->id}", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['permission']);
    }

    // =========================================================================
    // DELETE /api/shares/{share} — destroy
    // =========================================================================

    /** Owner can delete a share (204). */
    public function test_owner_can_delete_share(): void
    {
        $owner = User::factory()->create();
        $issue = Issue::factory()->for($owner)->create();
        $sharedUser = User::factory()->create();
        $share = IssueShare::factory()->for($issue)->for($sharedUser, 'user')->view()->create();

        $this->actingAs($owner)->deleteJson("/api/shares/{$share->id}")
            ->assertStatus(204);

        $this->assertDatabaseMissing('issue_shares', ['id' => $share->id]);
    }

    /** Non-owner cannot delete a share (403). */
    public function test_non_owner_cannot_delete_share(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $issue = Issue::factory()->for($owner)->create();
        $sharedUser = User::factory()->create();
        $share = IssueShare::factory()->for($issue)->for($sharedUser, 'user')->view()->create();

        $this->actingAs($other)->deleteJson("/api/shares/{$share->id}")
            ->assertStatus(403);

        $this->assertDatabaseHas('issue_shares', ['id' => $share->id]);
    }

    /** Unauthenticated request returns 401. */
    public function test_unauthenticated_cannot_delete_share(): void
    {
        $owner = User::factory()->create();
        $issue = Issue::factory()->for($owner)->create();
        $sharedUser = User::factory()->create();
        $share = IssueShare::factory()->for($issue)->for($sharedUser, 'user')->view()->create();

        $this->deleteJson("/api/shares/{$share->id}")->assertStatus(401);
    }

    // =========================================================================
    // Visibility integration — scopeAccessibleBy
    // =========================================================================

    /** Shared issue appears in recipient's accessible issues list. */
    public function test_shared_issue_appears_in_recipient_accessible_list(): void
    {
        $owner = User::factory()->create();
        $recipient = User::factory()->create();
        $issue = Issue::factory()->for($owner)->private()->create();

        // Before sharing: recipient cannot see the issue
        $before = Issue::accessibleBy($recipient)->pluck('id');
        $this->assertNotContains($issue->id, $before);

        // Share via API
        $this->actingAs($owner)->postJson("/api/issues/{$issue->id}/shares", [
            'email' => $recipient->email,
            'permission' => 'view',
        ])->assertStatus(201);

        // After sharing: recipient can see the issue
        $after = Issue::accessibleBy($recipient)->pluck('id');
        $this->assertContains($issue->id, $after);
    }

    /** After share is deleted, recipient can no longer see the private issue. */
    public function test_deleting_share_removes_issue_from_recipient_accessible_list(): void
    {
        $owner = User::factory()->create();
        $recipient = User::factory()->create();
        $issue = Issue::factory()->for($owner)->private()->create();
        $share = IssueShare::factory()->for($issue)->for($recipient, 'user')->view()->create();

        // Recipient can see the issue
        $before = Issue::accessibleBy($recipient)->pluck('id');
        $this->assertContains($issue->id, $before);

        // Delete the share
        $this->actingAs($owner)->deleteJson("/api/shares/{$share->id}")
            ->assertStatus(204);

        // Recipient can no longer see it
        $after = Issue::accessibleBy($recipient)->pluck('id');
        $this->assertNotContains($issue->id, $after);
    }
}
