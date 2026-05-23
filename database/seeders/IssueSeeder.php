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
        // Summary text is pinned (not random) so demo user always sees a realistic AI summary.
        Issue::factory()
            ->needsAttention()
            ->summaryReady(
                'The user reports intermittent 502 errors when accessing the billing portal. Logs indicate upstream timeout from the payment gateway after 30s. This correlates with peak-hour traffic spikes observed in the last 7 days.',
                'Increase payment gateway timeout to 60s and add retry logic with exponential backoff.',
            )
            ->inProgress()
            ->public()
            ->create([
                'user_id' => $users[0]->id,
                'category_id' => $nextCategory()->id,
                'title' => 'Billing portal returns 502 during peak hours',
                'description' => 'Users report intermittent 502 Bad Gateway errors when accessing the billing portal between 9 AM and 11 AM UTC. The error appears after approximately 30 seconds of loading. Server logs show the upstream payment gateway connection timing out. This seems to correlate with peak traffic from US-based enterprise customers.',
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
                'description' => 'Several users have reported being unable to log in after setting passwords containing characters like é, ñ, or ü. The login form accepts the password during registration but rejects it on subsequent login attempts. Resetting to an ASCII-only password resolves the issue. This affects approximately 3% of our user base.',
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
                'description' => 'The main dashboard takes over 8 seconds to load for users with more than 200 assigned issues. Database profiling reveals 200+ individual SELECT queries firing for the comments count on each issue card. This was not noticeable during initial development with smaller datasets.',
            ]);

        // 4. High priority + needs_attention (priority alone triggers flag)
        Issue::factory()
            ->highPriority()
            ->open()
            ->create([
                'user_id' => $users[3]->id,
                'category_id' => $nextCategory()->id,
                'title' => 'Mobile file uploads silently fail above 5MB',
                'description' => 'When uploading attachments larger than 5MB from mobile browsers (tested on iOS Safari and Chrome Android), the upload appears to start but completes without the file actually being attached. No error message is shown to the user. Desktop browsers handle the same files without issue up to the documented 20MB limit.',
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
                'description' => 'Three enterprise customers (Acme Corp, GlobalTech, DataFlow) have independently requested the ability to export their issue history as CSV files. They need this for quarterly compliance reporting. Currently they resort to copying data manually from the issue detail view, which is error-prone and time-consuming for 500+ issues.',
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
                'description' => 'Users are reporting that email notifications for new comments and status changes arrive 5-10 minutes after the event. The expected delivery time is under 30 seconds. This appears to be a queue throughput issue rather than an SMTP provider problem, as the emails are correctly formatted when they do arrive.',
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
                'description' => 'The issues list endpoint currently returns all issues in a single response. For teams with 1000+ issues, this results in response payloads exceeding 2MB and page load times over 5 seconds. We need cursor-based or offset pagination with a configurable page size, defaulting to 25 items per page.',
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
                'description' => 'Users who enable dark mode find that the preference resets to light mode after closing and reopening the browser. The toggle works correctly within a session but the setting is stored in component state rather than being persisted to localStorage or the user\'s profile in the database.',
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
                'description' => 'The welcome email sent to new users contains a typo in the second paragraph: \'We\'re excited to have you on boad\' should read \'We\'re excited to have you on board\'. This was spotted by the marketing team during their quarterly content review. Low priority but affects first impressions.',
            ]);

        // 10. Low + resolved + public
        Issue::factory()
            ->resolved()
            ->public()
            ->create([
                'user_id' => $users[4]->id,
                'category_id' => $nextCategory()->id,
                'title' => 'Update copyright year in footer to current year',
                'description' => 'The application footer still displays \'© 2024\' instead of \'© 2025\'. This needs to be updated across the main layout template. While we\'re at it, we should consider making this dynamic using the server\'s current year to avoid this recurring task.',
            ]);

        // 11. Low + open + summary failed
        Issue::factory()
            ->open()
            ->summaryFailed()
            ->create([
                'user_id' => $users[0]->id,
                'category_id' => $nextCategory()->id,
                'title' => 'Broken link in the help documentation sidebar',
                'description' => 'The help documentation sidebar contains a link to \'/docs/v1/integrations\' which returns a 404. This page was removed during the v2 documentation restructure last month. The link should either redirect to the new location at \'/docs/integrations\' or be removed from the sidebar navigation.',
            ]);

        // 12. Low + in-progress + deadline
        Issue::factory()
            ->inProgress()
            ->withDeadline(now()->addHours(24))
            ->create([
                'user_id' => $users[1]->id,
                'category_id' => $nextCategory()->id,
                'title' => 'Accessibility: missing alt text on dashboard charts',
                'description' => 'An accessibility audit flagged that the dashboard charts (priority distribution pie chart and weekly trend line chart) are missing alt text attributes. Screen readers announce them as \'unlabeled image\'. This is a WCAG 2.1 Level A violation that needs to be addressed before our next compliance review in 2 weeks.',
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
                'description' => 'Multiple users have complained that the session timeout of 15 minutes is too aggressive, especially when they\'re composing long issue descriptions or reviewing documentation. They lose their work when the session expires mid-task. Suggest increasing to 60 minutes or implementing a draft auto-save mechanism.',
            ]);

        // 14. Low + open + public
        Issue::factory()
            ->open()
            ->public()
            ->create([
                'user_id' => $users[3]->id,
                'category_id' => $nextCategory()->id,
                'title' => 'Add keyboard shortcut to create new issue',
                'description' => 'Power users have requested a keyboard shortcut (suggested: Ctrl+K or Cmd+K) to quickly open the new issue creation dialog from anywhere in the application. This would streamline the workflow for support agents who create dozens of issues per day. Similar shortcut patterns are used in Linear, GitHub, and Notion.',
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
                'description' => 'The issue search returns outdated results approximately 20% of the time. When a user updates an issue\'s status from \'open\' to \'resolved\', searching for open issues still shows the resolved issue for 30-60 seconds. This suggests the search index is not being invalidated properly on write operations.',
            ]);

        // 16. Low + open
        Issue::factory()
            ->open()
            ->create([
                'user_id' => $users[0]->id,
                'category_id' => $nextCategory()->id,
                'title' => 'PDF export does not include embedded images',
                'description' => 'When exporting an issue to PDF, any images embedded in the description field are missing from the output. The exported PDF shows empty placeholders where images should appear. This affects issues with screenshots or diagrams, which are critical for bug reports. The images render correctly in the web view.',
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
                'description' => 'Our API responses are missing standard rate limit headers (X-RateLimit-Limit, X-RateLimit-Remaining, X-RateLimit-Reset). Third-party integrations have no way to know their current usage or when limits reset, leading to unexpected 429 errors. Adding these headers is a prerequisite for our upcoming API partner program.',
            ]);

        // 18. Critical + open — extra needs_attention for safety margin
        Issue::factory()
            ->critical()
            ->open()
            ->create([
                'user_id' => $users[2]->id,
                'category_id' => $nextCategory()->id,
                'title' => 'Data export contains PII in plain text — security concern',
                'description' => 'The data export feature includes personally identifiable information (full names, email addresses, IP addresses) in unencrypted plain text CSV files. This violates our data handling policy and potentially GDPR requirements. Exported files should either redact PII fields or encrypt the export with a user-provided password.',
            ]);

        $this->command->info('IssueSeeder: seeded '.Issue::count().' issues.');
    }
}
