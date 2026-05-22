<?php

namespace Database\Factories;

use App\Enums\Priority;
use App\Enums\Status;
use App\Enums\SummaryStatus;
use App\Enums\Visibility;
use App\Models\Category;
use App\Models\Issue;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Issue>
 */
class IssueFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * Defaults: low priority, open status, private visibility — all safe values
     * that result in needs_attention = false (set automatically by saving event).
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'category_id' => Category::factory(),
            'title' => fake()->sentence(6),
            'description' => fake()->paragraph(),
            'priority' => Priority::Low,
            'status' => Status::Open,
            'visibility' => Visibility::Private,
            'summary' => null,
            'suggested_next_action' => null,
            'summary_status' => SummaryStatus::Pending,
            'needs_attention' => false,
            'deadline_at' => null,
        ];
    }

    /**
     * High-priority issue — needs_attention will be set to true by saving event.
     */
    public function highPriority(): static
    {
        return $this->state(fn (array $attributes) => [
            'priority' => Priority::High,
        ]);
    }

    /**
     * Critical-priority issue.
     */
    public function critical(): static
    {
        return $this->state(fn (array $attributes) => [
            'priority' => Priority::Critical,
        ]);
    }

    /**
     * Issue with a deadline within the attention threshold.
     */
    public function withDeadline(int $minutesFromNow = 30): static
    {
        return $this->state(fn (array $attributes) => [
            'deadline_at' => now()->addMinutes($minutesFromNow),
        ]);
    }

    /**
     * Publicly visible issue.
     */
    public function public(): static
    {
        return $this->state(fn (array $attributes) => [
            'visibility' => Visibility::Public,
        ]);
    }
}
