<?php

namespace Database\Factories;

use App\Models\IssueConversation;
use App\Models\IssueConversationMessage;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IssueConversationMessage>
 */
class IssueConversationMessageFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $role = fake()->randomElement(['user', 'assistant']);

        return [
            'conversation_id' => IssueConversation::factory(),
            'user_id' => $role === 'user' ? User::factory() : null,
            'role' => $role,
            'content' => fake()->paragraph(),
        ];
    }

    /**
     * State for a user message.
     */
    public function asUser(int $userId): static
    {
        return $this->state([
            'role' => 'user',
            'user_id' => $userId,
        ]);
    }

    /**
     * State for an assistant (AI) message.
     */
    public function asAssistant(): static
    {
        return $this->state([
            'role' => 'assistant',
            'user_id' => null,
        ]);
    }
}
