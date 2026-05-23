<?php

namespace Database\Seeders;

use App\Models\Comment;
use App\Models\Issue;
use App\Models\User;
use Illuminate\Database\Seeder;

class CommentSeeder extends Seeder
{
    /**
     * Seed 2-4 comments per issue.
     *
     * Author is always a user different from the issue owner for realistic demo data.
     * Target: ≥30 total comments across all issues.
     *
     * All 18 issues have pinned realistic comment threads — no faker fallback.
     */

    /**
     * Pinned comment threads keyed by 0-based issue index.
     * Each entry is an ordered list of comment bodies.
     *
     * @var array<int, list<string>>
     */
    private const PINNED_COMMENTS = [
        // Issue #1 — "Billing portal returns 502 during peak hours"
        0 => [
            'I can reproduce this consistently between 09:00–11:00 UTC. The gateway logs show a 30-second hard timeout before the 502 is returned to the client.',
            'Confirmed on our end too. The payment provider has a known latency spike during US business hours. We should buffer with a local retry queue.',
            'Opened a ticket with the payment gateway team. They acknowledged the issue and suggested increasing the client-side timeout to 60s as a short-term fix.',
        ],
        // Issue #2 — "Authentication fails for special-character passwords"
        1 => [
            "I can reproduce with the password 'über123!' — registration succeeds but login fails. The bcrypt hash looks different between the two attempts.",
            'Traced it to the input sanitizer stripping non-ASCII characters before hashing. The registration path skips this sanitizer but the login path doesn\'t.',
            'Fix is straightforward — remove the sanitizer from the login controller. It was added as an XSS precaution but bcrypt handles raw bytes safely.',
        ],
        // Issue #3 — "Dashboard N+1 query degradation at 200+ issues"
        2 => [
            'Confirmed with Laravel Debugbar — 247 queries on a test account with 200 issues. Each card triggers a separate COUNT query for comments.',
            "Adding `withCount('comments')` to the dashboard query brings it down to 3 queries total. Testing on staging now.",
        ],
        // Issue #4 — "Mobile file uploads silently fail above 5MB"
        3 => [
            'Tested on iPhone 14 with iOS 17 — uploads over 5MB hang indefinitely. The XHR request shows a 413 from nginx before reaching Laravel.',
            'The nginx client_max_body_size is set to 5M in the mobile-specific server block but 20M in the desktop one. This is a config discrepancy, not a code issue.',
            'Updated nginx config to use 20M consistently. Also added a client-side file size check with a proper error toast.',
        ],
        // Issue #5 — "CSV bulk export requested by enterprise customers"
        4 => [
            "Acme Corp alone has 1,200 issues they need exported for their Q4 audit. They've been doing it manually for three quarters now.",
            'I can prototype a basic CSV endpoint in a day. We should include filters for date range, status, and category at minimum.',
            "Product approved this for next sprint. Let's use Laravel's StreamedResponse so we don't run out of memory on large exports.",
        ],
        // Issue #6 — "Email notifications delayed by up to 10 minutes"
        5 => [
            'Tracked this down to the queue worker running with only 1 process. Bumping to 3 workers dropped average delay from 8 min to under 30 seconds.',
            'Deployed the fix to staging — notifications now land within 15 seconds under normal load. Marking ready for production.',
        ],
        // Issue #7 — "Add pagination to the issues list view"
        6 => [
            'We already have the Eloquent pagination setup — just need to wire it into the controller. The frontend will need a page selector component.',
            'Went with cursor-based pagination since offset pagination performs poorly past page 100 with our current indexes.',
        ],
        // Issue #8 — "Dark mode preference not persisted across sessions"
        7 => [
            'Quick fix would be localStorage, but if we want it to sync across devices we should store it in the user profile table.',
            'Added a `preferences` JSON column to users. Dark mode, timezone, and language can all go there. Migration is ready for review.',
        ],
        // Issue #9 — "Typo in the onboarding welcome email template"
        8 => [
            'Fixed. Also ran a spell check across all email templates — found two more typos in the password reset template.',
            'Merged and deployed. The corrected emails are already going out.',
        ],
        // Issue #10 — "Update copyright year in footer to current year"
        9 => [
            "Changed it to `{{ date('Y') }}` in the Blade layout. No more manual updates needed each January.",
            'Good call. Also updated the email footer template which had the same hardcoded year.',
        ],
        // Issue #11 — "Broken link in the help documentation sidebar"
        10 => [
            'The broken link points to /docs/v1/integrations which was removed in the v2 doc restructure. We need to redirect it to /docs/integrations or update the sidebar nav.',
            'Added a redirect rule in the nginx config for now. A proper fix should update the sidebar template source.',
        ],
        // Issue #12 — "Accessibility: missing alt text on dashboard charts"
        11 => [
            "I've added descriptive alt text to both charts. The pie chart now reads 'Priority distribution: 45% medium, 30% high, 15% low, 10% critical'. The line chart describes the trend direction.",
            'Ran the axe accessibility scanner — the alt text warnings are resolved. Two other minor issues flagged: insufficient color contrast on the muted text and missing form labels on the filter dropdowns.',
            'The contrast issues are pre-existing from the design system. Logging a separate ticket for those.',
        ],
        // Issue #13 — "Session timeout too aggressive — 15 min is too short"
        12 => [
            '60 minutes feels too long from a security perspective. How about 30 minutes with an auto-save draft feature?',
            'Compromise: 30 min session with a \'session expiring\' warning at 25 min that lets users extend. Plus localStorage draft saves every 30 seconds.',
            'That works. The draft auto-save alone would solve most of the complaints since users wouldn\'t lose their work.',
        ],
        // Issue #14 — "Add keyboard shortcut to create new issue"
        13 => [
            "Cmd+K is already used by the browser's address bar on some systems. Cmd+I might be safer for 'new Issue'.",
            "Went with Cmd+K since that's the established convention in dev tools (Linear, VS Code, Notion). Added a listener that prevents browser default.",
        ],
        // Issue #15 — "Search results intermittently return stale data"
        14 => [
            'The search index updates are running asynchronously on the queue, which explains the 30-60 second delay. The write completes before the index job runs.',
            'Switched to synchronous index updates for status changes since those are lightweight. Full-text re-indexing still runs async.',
            'Tested with 50 rapid status changes — search results are now consistent within 2 seconds. Async full-text updates still take 10-15 seconds but that\'s acceptable.',
        ],
        // Issue #16 — "PDF export does not include embedded images"
        15 => [
            'Reproduced with the Chromium-based PDF renderer. Images hosted on S3 with presigned URLs expire before the headless browser can fetch them during export.',
            'Proposed fix: pre-download images to temp storage before rendering, or embed them as base64 data URIs in the HTML template.',
            'Base64 approach tested — works for images under 1MB. Larger images still need the pre-download path. Both code paths are straightforward.',
        ],
        // Issue #17 — "API rate limit headers not included in responses"
        16 => [
            "We're using Laravel's built-in throttle middleware but it doesn't add the headers by default. Need a custom middleware wrapper.",
            'Added a RateLimitHeaders middleware that reads from the RateLimiter facade and appends X-RateLimit-Limit, X-RateLimit-Remaining, and X-RateLimit-Reset to every API response.',
            'Tested with the Postman collection — headers are present and values decrement correctly. Ready for partner documentation.',
        ],
        // Issue #18 — "Data export contains PII in plain text — security concern"
        17 => [
            'Confirmed — the full CSV export includes email addresses, IP logs, and full names in plaintext. This is a clear GDPR violation for EU customers.',
            'Recommend two tracks: (1) immediate hotfix to redact email and IP fields, (2) longer-term encrypted export with password protection for compliance teams.',
            'Legal has been notified. They want the redaction hotfix deployed within 48 hours and a full audit of what other endpoints may expose PII.',
        ],
    ];

    public function run(): void
    {
        $users = User::orderBy('id')->get();
        $issues = Issue::orderBy('id')->get();

        foreach ($issues as $index => $issue) {
            $pinnedBodies = self::PINNED_COMMENTS[$index] ?? [];
            $potentialAuthors = $users->where('id', '!=', $issue->user_id)->values();

            foreach ($pinnedBodies as $body) {
                Comment::factory()->create([
                    'issue_id' => $issue->id,
                    'user_id' => $potentialAuthors->random()->id,
                    'body' => $body,
                ]);
            }
        }

        $this->command->info('CommentSeeder: seeded '.Comment::count().' comments across '.Issue::count().' issues.');
    }
}
