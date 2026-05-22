<?php

namespace Database\Seeders;

use App\Enums\Priority;
use App\Enums\Status;
use App\Models\Category;
use App\Models\Issue;
use App\Models\User;
use Illuminate\Database\Seeder;

class IssueSeeder extends Seeder
{
    /**
     * Seed 18 issues spanning all priority levels, statuses, and visibilities.
     *
     * Coverage targets (from Technical Guidance §4):
     * - All 4 Priority cases represented (at least one each)
     * - All 3 Status cases represented
     * - Mix of public/private (~4 public / 14 private)
     * - 5-7 with deadlines, rest without
     * - ≥2 with summary_status=ready (summaryReady() state)
     * - ≥3 with needs_attention=true (critical/high priority triggers saving event)
     * - Distributed across all 5 users and 6 categories
     *
     * needs_attention is NOT set manually — computed by Issue::saving() event.
     */
    public function run(): void
    {
        $users = User::all();
        $categories = Category::all();

        // Helper: pick a user different from the given one.
        $otherUser = fn (User $owner) => $users->where('id', '!=', $owner->id)->random();

        // Round-robin index for category distribution.
        $catIndex = 0;
        $nextCategory = function () use ($categories, &$catIndex): Category {
            $cat = $categories[$catIndex % $categories->count()];
            $catIndex++;

            return $cat;
        };

        // -------------------------------------------------------------------
        // Issues with needs_attention via critical priority (≥3 required)
        // -------------------------------------------------------------------

        // 1. Critical + needs_attention + summary ready + deadline
        Issue::factory()
            ->needsAttention()
            ->summaryReady()
            ->inProgress()
            ->public()
            ->create([
                'user_id' => $users[0]->id,
                'category_id' => $nextCategory()->id,
                'title' => 'Billing portal returns 502 during peak hours',
            ]);

        // 2. Critical + needs_attention (no deadline — priority alone triggers it)
        Issue::factory()
            ->critical()
            ->open()
            ->public()
            ->create([
                'user_id' => $users[1]->id,
                'category_id' => $nextCategory()->id,
                'title' => 'Authentication fails for special-character passwords',
            ]);

        // 3. Critical + needs_attention + summary ready
        Issue::factory()
            ->critical()
            ->summaryReady()
            ->inProgress()
            ->withDeadline(now()->addMinutes(45))
            ->create([
                'user_id' => $users[2]->id,
                'category_id' => $nextCategory()->id,
                'title' => 'Dashboard N+1 query degradation at 200+ issues',
            ]);

        // 4. High priority + needs_attention (priority alone triggers flag)
        Issue::factory()
            ->highPriority()
            ->open()
            ->create([
                'user_id' => $users[3]->id,
                'category_id' => $nextCategory()->id,
                'title' => 'Mobile file uploads silently fail above 5MB',
            ]);

        // 5. High priority + needs_attention + deadline
        Issue::factory()
            ->highPriority()
            ->inProgress()
            ->withDeadline(now()->addMinutes(90))
            ->create([
                'user_id' => $users[4]->id,
                'category_id' => $nextCategory()->id,
                'title' => 'CSV bulk export requested by enterprise customers',
            ]);

        // -------------------------------------------------------------------
        // Issues with medium priority
        // -------------------------------------------------------------------

        // 6. Medium + resolved + public
        Issue::factory()
            ->priority(Priority::Medium)
            ->resolved()
            ->public()
            ->create([
                'user_id' => $users[0]->id,
                'category_id' => $nextCategory()->id,
                'title' => 'Email notifications delayed by up to 10 minutes',
            ]);

        // 7. Medium + in-progress + deadline
        Issue::factory()
            ->priority(Priority::Medium)
            ->inProgress()
            ->withDeadline(now()->addHours(3))
            ->create([
                'user_id' => $users[1]->id,
                'category_id' => $nextCategory()->id,
                'title' => 'Add pagination to the issues list view',
            ]);

        // 8. Medium + open + summary processing
        Issue::factory()
            ->priority(Priority::Medium)
            ->open()
            ->summaryProcessing()
            ->create([
                'user_id' => $users[2]->id,
                'category_id' => $nextCategory()->id,
                'title' => 'Dark mode preference not persisted across sessions',
            ]);

        // -------------------------------------------------------------------
        // Issues with low priority
        // -------------------------------------------------------------------

        // 9. Low + open (default)
        Issue::factory()
            ->open()
            ->create([
                'user_id' => $users[3]->id,
                'category_id' => $nextCategory()->id,
                'title' => 'Typo in the onboarding welcome email template',
            ]);

        // 10. Low + resolved + public
        Issue::factory()
            ->resolved()
            ->public()
            ->create([
                'user_id' => $users[4]->id,
                'category_id' => $nextCategory()->id,
                'title' => 'Update copyright year in footer to current year',
            ]);

        // 11. Low + open + summary failed
        Issue::factory()
            ->open()
            ->summaryFailed()
            ->create([
                'user_id' => $users[0]->id,
                'category_id' => $nextCategory()->id,
                'title' => 'Broken link in the help documentation sidebar',
            ]);

        // 12. Low + in-progress + deadline
        Issue::factory()
            ->inProgress()
            ->withDeadline(now()->addHours(24))
            ->create([
                'user_id' => $users[1]->id,
                'category_id' => $nextCategory()->id,
                'title' => 'Accessibility: missing alt text on dashboard charts',
            ]);

        // -------------------------------------------------------------------
        // Additional issues for count coverage (target 18 total)
        // -------------------------------------------------------------------

        // 13. Medium + resolved
        Issue::factory()
            ->priority(Priority::Medium)
            ->resolved()
            ->create([
                'user_id' => $users[2]->id,
                'category_id' => $nextCategory()->id,
                'title' => 'Session timeout too aggressive — 15 min is too short',
            ]);

        // 14. Low + open + public
        Issue::factory()
            ->open()
            ->public()
            ->create([
                'user_id' => $users[3]->id,
                'category_id' => $nextCategory()->id,
                'title' => 'Add keyboard shortcut to create new issue',
            ]);

        // 15. High + resolved + public
        Issue::factory()
            ->highPriority()
            ->resolved()
            ->public()
            ->create([
                'user_id' => $users[4]->id,
                'category_id' => $nextCategory()->id,
                'title' => 'Search results intermittently return stale data',
            ]);

        // 16. Low + open
        Issue::factory()
            ->open()
            ->create([
                'user_id' => $users[0]->id,
                'category_id' => $nextCategory()->id,
                'title' => 'PDF export does not include embedded images',
            ]);

        // 17. Medium + open + deadline
        Issue::factory()
            ->priority(Priority::Medium)
            ->open()
            ->withDeadline(now()->addHours(48))
            ->create([
                'user_id' => $users[1]->id,
                'category_id' => $nextCategory()->id,
                'title' => 'API rate limit headers not included in responses',
            ]);

        // 18. Critical + open — extra needs_attention for safety margin
        Issue::factory()
            ->critical()
            ->open()
            ->create([
                'user_id' => $users[2]->id,
                'category_id' => $nextCategory()->id,
                'title' => 'Data export contains PII in plain text — security concern',
            ]);

        $this->command->info('IssueSeeder: seeded '.Issue::count().' issues.');
    }
}
