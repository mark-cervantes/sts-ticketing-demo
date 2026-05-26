<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreChatMessageRequest;
use App\Models\Issue;
use App\Services\Ai\ChatService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Stateless AI chat endpoint for issues.
 *
 * The client sends the full session history in the request body.
 * Nothing is persisted here — use IssueConversationController to save.
 *
 * Authorization: IssuePolicy::view() via $this->authorize('view', $issue).
 * Rate limit: named limiter 'chat' — 10 per user per issue per hour.
 *
 * @see task 09.01 / ADR-002 / IssueSseController (SSE shape reference)
 */
class IssueChatController extends Controller
{
    public function __construct(private readonly ChatService $chatService) {}

    /**
     * POST /api/issues/{issue}/chat
     *
     * Stateless streaming endpoint. Accepts message + history, streams AI response.
     * Returns 503 if no AI provider configured.
     * Returns 429 if rate limited.
     */
    public function chat(StoreChatMessageRequest $request, Issue $issue): StreamedResponse|JsonResponse
    {
        $this->authorize('view', $issue);

        if (! $this->chatService->isConfigured()) {
            return response()->json(['message' => 'AI provider is not configured.'], 503);
        }

        // Rate limit check
        $key = 'chat:'.$request->user()->id.':'.$issue->id;

        if (RateLimiter::tooManyAttempts($key, 10)) {
            $seconds = RateLimiter::availableIn($key);

            return response()->json([
                'message' => 'Too many requests. Please wait '.$seconds.' seconds.',
            ], 429);
        }

        RateLimiter::hit($key, 3600);

        $validated = $request->validated();
        $history = $validated['history'] ?? [];
        $message = $validated['message'];

        return new StreamedResponse(function () use ($issue, $history, $message): void {
            foreach ($this->chatService->streamChat($issue, $history, $message) as $token) {
                echo 'data: '.json_encode(['token' => $token])."\n\n";
                flush();
            }

            echo "data: [DONE]\n\n";
            flush();
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'X-Accel-Buffering' => 'no',
            'Connection' => 'keep-alive',
        ]);
    }
}
