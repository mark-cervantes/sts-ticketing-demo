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
     * Realistic summary/action pairs for summaryReady() state.
     * Drawn randomly when no explicit args are provided.
     *
     * @var array<int, array{summary: string, action: string}>
     */
    private static array $summaryBank = [
        [
            'summary' => 'The user reports intermittent 502 errors when accessing the billing portal. Logs indicate upstream timeout from the payment gateway after 30s. This correlates with peak-hour traffic spikes observed in the last 7 days.',
            'action' => 'Increase payment gateway timeout to 60s and add retry logic with exponential backoff.',
        ],
        [
            'summary' => 'Account login fails for users with special characters in passwords. Root cause traced to overly strict input sanitization on the authentication endpoint that strips non-ASCII characters before hash comparison.',
            'action' => 'Update the authentication middleware to use raw password bytes for hashing without pre-sanitization.',
        ],
        [
            'summary' => 'Feature request for bulk export of issue history as CSV. Multiple enterprise customers have raised this in the last quarter. Current workaround involves manual copy-paste from the issue detail view.',
            'action' => 'Implement a CSV export endpoint at GET /issues/export with date-range and status filters.',
        ],
        [
            'summary' => 'The dashboard performance degrades noticeably when a user has more than 200 assigned issues. Profiling shows N+1 queries on the comments relationship during the summary card render.',
            'action' => 'Add eager loading for comments and category relationships in the dashboard query.',
        ],
        [
            'summary' => 'Users on mobile devices cannot attach files larger than 5MB to issues despite the documented limit being 20MB. The upload form silently fails without an error message, leaving users confused.',
            'action' => 'Increase the mobile file upload timeout and display a progress indicator with clear error feedback when limits are exceeded.',
        ],
    ];

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

    // -------------------------------------------------------------------------
    // Status states
    // -------------------------------------------------------------------------

    /** Generic status setter. */
    public function status(Status $status): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => $status,
        ]);
    }

    /** Open status shortcut. */
    public function open(): static
    {
        return $this->status(Status::Open);
    }

    /** In-progress status shortcut. */
    public function inProgress(): static
    {
        return $this->status(Status::InProgress);
    }

    /** Resolved status shortcut. */
    public function resolved(): static
    {
        return $this->status(Status::Resolved);
    }

    // -------------------------------------------------------------------------
    // Priority states
    // -------------------------------------------------------------------------

    /** Generic priority setter. */
    public function priority(Priority $priority): static
    {
        return $this->state(fn (array $attributes) => [
            'priority' => $priority,
        ]);
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

    // -------------------------------------------------------------------------
    // Visibility states
    // -------------------------------------------------------------------------

    /**
     * Publicly visible issue.
     */
    public function public(): static
    {
        return $this->state(fn (array $attributes) => [
            'visibility' => Visibility::Public,
        ]);
    }

    /**
     * Private visibility issue.
     */
    public function private(): static
    {
        return $this->state(fn (array $attributes) => [
            'visibility' => Visibility::Private,
        ]);
    }

    // -------------------------------------------------------------------------
    // Deadline state
    // -------------------------------------------------------------------------

    /**
     * Issue with a deadline.
     *
     * Pass an explicit Carbon instance, or let it default to a random time
     * within the next 15-180 minutes (within the attention threshold).
     */
    public function withDeadline(?\Carbon\Carbon $deadline = null): static
    {
        return $this->state(fn (array $attributes) => [
            'deadline_at' => $deadline ?? now()->addMinutes(fake()->numberBetween(15, 180)),
        ]);
    }

    // -------------------------------------------------------------------------
    // Summary states
    // -------------------------------------------------------------------------

    /**
     * Issue with a ready AI summary.
     *
     * When called without arguments, picks a random entry from $summaryBank.
     * Both args must be non-null — enforced by the summary bank fallback.
     */
    public function summaryReady(?string $summary = null, ?string $actionItem = null): static
    {
        if ($summary === null || $actionItem === null) {
            $pair = fake()->randomElement(self::$summaryBank);
            $summary ??= $pair['summary'];
            $actionItem ??= $pair['action'];
        }

        return $this->state(fn (array $attributes) => [
            'summary_status' => SummaryStatus::Ready,
            'summary' => $summary,
            'suggested_next_action' => $actionItem,
        ]);
    }

    /**
     * Issue with summary in processing state.
     */
    public function summaryProcessing(): static
    {
        return $this->state(fn (array $attributes) => [
            'summary_status' => SummaryStatus::Processing,
        ]);
    }

    /**
     * Issue with a failed summary attempt.
     */
    public function summaryFailed(): static
    {
        return $this->state(fn (array $attributes) => [
            'summary_status' => SummaryStatus::Failed,
        ]);
    }

    // -------------------------------------------------------------------------
    // Compound convenience states
    // -------------------------------------------------------------------------

    /**
     * Convenience state: critical priority + tight deadline (15 min).
     * The saving event will compute needs_attention=true from both triggers.
     */
    public function needsAttention(): static
    {
        return $this->critical()->withDeadline(now()->addMinutes(15));
    }
}
