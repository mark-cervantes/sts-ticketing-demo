<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests for GET /api/chat/suggestions.
 *
 * @see task 09.04 / IssueChatController::suggestions()
 */
class ChatSuggestionsEndpointTest extends TestCase
{
    use RefreshDatabase;

    // -------------------------------------------------------------------------
    // Authentication
    // -------------------------------------------------------------------------

    public function test_unauthenticated_user_cannot_get_suggestions(): void
    {
        $this->getJson('/api/chat/suggestions')
            ->assertUnauthorized();
    }

    // -------------------------------------------------------------------------
    // Response shape
    // -------------------------------------------------------------------------

    public function test_suggestions_returns_json_array(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->getJson('/api/chat/suggestions');

        $response->assertOk()
            ->assertJsonIsArray();
    }

    public function test_suggestions_includes_create_ticket_chip(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->getJson('/api/chat/suggestions');

        $response->assertOk();
        $chips = $response->json();

        $this->assertContains('Create a follow-up ticket', $chips);
    }

    public function test_suggestions_chips_are_strings(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->getJson('/api/chat/suggestions');

        $chips = $response->json();

        foreach ($chips as $chip) {
            $this->assertIsString($chip);
        }
    }
}
