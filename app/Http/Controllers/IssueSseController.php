<?php

namespace App\Http\Controllers;

use App\Enums\SummaryStatus;
use App\Models\Issue;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * SSE endpoint for live summary status updates.
 *
 * Polls the DB every 2 s and emits a single terminal event when the
 * summary_status transitions to 'ready' or 'failed'. Sends a keepalive
 * comment every 15 s to prevent proxy disconnects. Exits after 120 s.
 *
 * Note on output buffering: PHP's output_buffering ini is disabled in
 * production (Sail nginx + php-fpm). We do not call ob_end_clean() here
 * because Laravel's test harness (streamedContent) wraps sendContent() in
 * its own ob_start() callback and calling ob_end_clean() would destroy that
 * capture buffer. The StreamedResponse itself calls flush() between each chunk.
 *
 * @see SRS §FR-12 / ADR-001
 */
class IssueSseController extends Controller
{
    /** How long between DB polls (seconds). */
    private const POLL_INTERVAL = 2;

    /** How often to send a keepalive comment (seconds). */
    private const KEEPALIVE_INTERVAL = 15;

    /** Maximum time to stream before closing (seconds). */
    private const TIMEOUT = 120;

    /**
     * Handle the incoming SSE request.
     *
     * GET /api/issues/{issue}/stream
     * Auth middleware + IssuePolicy::view() guard access.
     */
    public function __invoke(Request $request, Issue $issue): StreamedResponse
    {
        $this->authorize('view', $issue);

        return new StreamedResponse(function () use ($issue): void {
            $startedAt = time();
            $lastKeepaliveAt = $startedAt;

            while (true) {
                $now = time();
                $elapsed = $now - $startedAt;

                // Hard timeout — close gracefully; client EventSource reconnects.
                if ($elapsed >= self::TIMEOUT) {
                    echo ": timeout\n\n";
                    flush();
                    break;
                }

                // Refresh the model from DB each cycle.
                /** @var Issue|null $fresh */
                $fresh = $issue->fresh();

                if ($fresh === null) {
                    // Issue was deleted while streaming.
                    echo "event: error\n";
                    echo "data: {\"message\":\"Issue not found\"}\n\n";
                    flush();
                    break;
                }

                $status = $fresh->summary_status;

                if ($status === SummaryStatus::Ready) {
                    echo "event: summary.ready\n";
                    echo 'data: '.json_encode([
                        'summary_status' => $status->value,
                        'summary' => $fresh->summary,
                        'suggested_next_action' => $fresh->suggested_next_action,
                    ])."\n\n";
                    flush();
                    break;
                }

                if ($status === SummaryStatus::Failed) {
                    echo "event: summary.failed\n";
                    echo 'data: '.json_encode([
                        'summary_status' => $status->value,
                    ])."\n\n";
                    flush();
                    break;
                }

                // Send keepalive comment every KEEPALIVE_INTERVAL seconds.
                if ($now - $lastKeepaliveAt >= self::KEEPALIVE_INTERVAL) {
                    echo ": keepalive\n\n";
                    flush();
                    $lastKeepaliveAt = $now;
                }

                // Abort if the client disconnected.
                if (connection_aborted()) {
                    break;
                }

                sleep(self::POLL_INTERVAL);
            }
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'X-Accel-Buffering' => 'no',
            'Connection' => 'keep-alive',
        ]);
    }
}
