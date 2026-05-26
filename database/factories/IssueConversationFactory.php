<?php

namespace Database\Factories;

use App\Models\Issue;
use App\Models\IssueConversation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IssueConversation>
 */
class IssueConversationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'issue_id' => Issue::factory(),
            'saved_by' => User::factory(),
            'title' => fake()->optional()->sentence(4),
        ];
    }
}
