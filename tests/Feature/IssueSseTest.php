<?php

namespace Tests\Feature;

use App\Models\Issue;
use App\Models\IssueShare;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * SSE endpoint feature tests — GET /api/issues/{issue}/stream
 *
 * @see SRS §FR-12 / ADR-001 / task 03.06.00
 */
class IssueSseTest extends TestCase
{
    use RefreshDatabase;

    // =========================================================================
    // Auth + access guards
    // =========================================================================

    /** Unauthenticated request returns 401. */
    public function test_unauthenticated_request_returns_401(): void
    {
        $issue = Issue::factory()->create();

        $response = $this->getJson("/api/issues/{$issue->id}/stream");

        $response->assertStatus(401);
    }

    /** Authenticated user who does not own and is not shared returns 403. */
    public function test_unauthorized_user_returns_403(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();

        $issue = Issue::factory()->for($owner)->summaryProcessing()->create();

        $response = $this->actingAs($other)->getJson("/api/issues/{$issue->id}/stream");

        $response->assertStatus(403);
    }

    /** Non-existent issue returns 404. */
    public function test_nonexistent_issue_returns_404(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->getJson('/api/issues/999999/stream');

        $response->assertStatus(404);
    }

    // =========================================================================
    // Response headers
    // =========================================================================

    /** Owner request on a ready issue returns correct SSE headers. */
    public function test_sse_response_has_correct_content_type(): void
    {
        $user = User::factory()->create();
        $issue = Issue::factory()->for($user)->summaryReady()->create();

        $response = $this->actingAs($user)->get("/api/issues/{$issue->id}/stream");

        $response->assertStatus(200);
        $this->assertStringContainsString('text/event-stream', $response->headers->get('Content-Type') ?? '');
        $response->assertHeader('Cache-Control', 'no-cache, private');
    }

    /** X-Accel-Buffering header is set to disable nginx buffering. */
    public function test_sse_response_has_x_accel_buffering_header(): void
    {
        $user = User::factory()->create();
        $issue = Issue::factory()->for($user)->summaryReady()->create();

        $response = $this->actingAs($user)->get("/api/issues/{$issue->id}/stream");

        $response->assertHeader('X-Accel-Buffering', 'no');
    }

    // =========================================================================
    // Event payload — terminal states (ready / failed)
    // =========================================================================

    /** Issue already in ready state emits summary.ready event immediately. */
    public function test_ready_issue_emits_summary_ready_event(): void
    {
        $user = User::factory()->create();
        $issue = Issue::factory()->for($user)->summaryReady(
            summary: 'Bug traced to null pointer in payment module.',
            actionItem: 'Add null guard before invoking gateway client.',
        )->create();

        $response = $this->actingAs($user)->get("/api/issues/{$issue->id}/stream");

        $response->assertStatus(200);

        $content = $response->streamedContent();

        $this->assertStringContainsString('event: summary.ready', $content);
        $this->assertStringContainsString('"summary_status":"ready"', $content);
        $this->assertStringContainsString('Bug traced to null pointer in payment module.', $content);
        $this->assertStringContainsString('Add null guard before invoking gateway client.', $content);
    }

    /** Issue in failed state emits summary.failed event immediately. */
    public function test_failed_issue_emits_summary_failed_event(): void
    {
        $user = User::factory()->create();
        $issue = Issue::factory()->for($user)->summaryFailed()->create();

        $response = $this->actingAs($user)->get("/api/issues/{$issue->id}/stream");

        $response->assertStatus(200);

        $content = $response->streamedContent();

        $this->assertStringContainsString('event: summary.failed', $content);
        $this->assertStringContainsString('"summary_status":"failed"', $content);
    }

    // =========================================================================
    // Access via share
    // =========================================================================

    /** A user with a share row can access the SSE stream. */
    public function test_shared_user_can_access_sse_stream(): void
    {
        $owner = User::factory()->create();
        $sharedUser = User::factory()->create();

        $issue = Issue::factory()->for($owner)->summaryReady()->create();

        IssueShare::factory()->create([
            'issue_id' => $issue->id,
            'user_id' => $sharedUser->id,
        ]);

        $response = $this->actingAs($sharedUser)->get("/api/issues/{$issue->id}/stream");

        $response->assertStatus(200);
        $this->assertStringContainsString('text/event-stream', $response->headers->get('Content-Type') ?? '');
    }

    // =========================================================================
    // Event data shape
    // =========================================================================

    /** The ready event payload is valid JSON with required fields. */
    public function test_ready_event_payload_is_valid_json_with_required_fields(): void
    {
        $user = User::factory()->create();
        $issue = Issue::factory()->for($user)->summaryReady(
            summary: 'Test summary text.',
            actionItem: 'Test next action.',
        )->create();

        $response = $this->actingAs($user)->get("/api/issues/{$issue->id}/stream");
        $content = $response->streamedContent();

        // Extract the data: line from the event
        preg_match('/^data: (.+)$/m', $content, $matches);
        $this->assertNotEmpty($matches, 'No data: line found in SSE output');

        $payload = json_decode($matches[1], true);
        $this->assertIsArray($payload);
        $this->assertArrayHasKey('summary_status', $payload);
        $this->assertArrayHasKey('summary', $payload);
        $this->assertArrayHasKey('suggested_next_action', $payload);
        $this->assertSame('ready', $payload['summary_status']);
        $this->assertSame('Test summary text.', $payload['summary']);
        $this->assertSame('Test next action.', $payload['suggested_next_action']);
    }

    /** The failed event payload contains summary_status:failed. */
    public function test_failed_event_payload_contains_summary_status_failed(): void
    {
        $user = User::factory()->create();
        $issue = Issue::factory()->for($user)->summaryFailed()->create();

        $response = $this->actingAs($user)->get("/api/issues/{$issue->id}/stream");
        $content = $response->streamedContent();

        preg_match('/^data: (.+)$/m', $content, $matches);
        $this->assertNotEmpty($matches, 'No data: line found in SSE output');

        $payload = json_decode($matches[1], true);
        $this->assertIsArray($payload);
        $this->assertArrayHasKey('summary_status', $payload);
        $this->assertSame('failed', $payload['summary_status']);
    }

    // =========================================================================
    // Route registration
    // =========================================================================

    /** The SSE route is registered and responds to GET. */
    public function test_sse_route_is_registered(): void
    {
        $user = User::factory()->create();
        $issue = Issue::factory()->for($user)->summaryReady()->create();

        $response = $this->actingAs($user)->get("/api/issues/{$issue->id}/stream");

        // 200 confirms the route is wired; 405 or 404 would indicate missing route.
        $response->assertStatus(200);
    }
}
