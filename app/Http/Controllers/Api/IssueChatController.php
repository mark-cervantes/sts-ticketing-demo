<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreChatMessageRequest;
use App\Http\Requests\StoreToolConfirmRequest;
use App\Models\Issue;
use App\Services\Ai\ChatService;
use App\Services\Ai\Tools\ChatToolRegistry;
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
 * @see task 09.01 / task 09.04 / ADR-002 / IssueSseController (SSE shape reference)
 */
class IssueChatController extends Controller
{
    public function __construct(
        private readonly ChatService $chatService,
        private readonly ChatToolRegistry $registry,
    ) {}

    /**
     * POST /api/issues/{issue}/chat
     *
     * Stateless streaming endpoint. Accepts message + history, streams AI response.
     * Returns 503 if no AI provider configured.
     * Returns 429 if rate limited.
     *
     * SSE event shapes:
     *  - Regular token:  data: {"token":"<string>"}\n\n
     *  - Tool call:      data: {"type":"tool_call","tool":"...","arguments":{...},"requires_confirmation":<bool>}\n\n
     *  - Done sentinel:  data: [DONE]\n\n
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
            foreach ($this->chatService->streamChat($issue, $history, $message) as $chunk) {
                if (is_array($chunk) && ($chunk['type'] ?? null) === 'tool_call') {
                    // Tool call event — emit verbatim as JSON (not wrapped in {"token":...})
                    echo 'data: '.json_encode($chunk)."\n\n";
                } else {
                    // Regular content token
                    echo 'data: '.json_encode(['token' => $chunk])."\n\n";
                }

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

    /**
     * POST /api/issues/{issue}/chat/tool-confirm
     *
     * Executes a tool after the user explicitly confirmed. Returns ChatToolResult as JSON.
     *
     * Authorization: view on issue (same as chat) + create on Issue::class (future-proofing).
     */
    public function confirmTool(StoreToolConfirmRequest $request, Issue $issue): JsonResponse
    {
        $this->authorize('view', $issue);
        $this->authorize('create', Issue::class);

        $validated = $request->validated();
        $toolName = $validated['tool'];
        $arguments = $validated['arguments'];

        if (! $this->registry->hasTool($toolName)) {
            return response()->json([
                'message' => "Unknown tool: {$toolName}",
            ], 422);
        }

        $result = $this->registry->executeToolConfirmed(
            $toolName,
            $arguments,
            $issue,
            $request->user(),
        );

        return response()->json([
            'tool_name' => $result->toolName,
            'success' => $result->success,
            'message' => $result->message,
            'data' => $result->data,
            'pending_confirmation' => $result->pendingConfirmation,
        ], $result->success ? 200 : 422);
    }

    /**
     * GET /api/chat/suggestions
     *
     * Returns suggestion chip texts from all registered tools.
     * Declared before the {issue} wildcard group in routes/api.php.
     */
    public function suggestions(): JsonResponse
    {
        return response()->json($this->registry->getSuggestionChips());
    }
}
