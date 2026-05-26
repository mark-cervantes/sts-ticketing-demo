<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ContinueConversationRequest;
use App\Http\Requests\StoreConversationRequest;
use App\Models\Issue;
use App\Models\IssueConversation;
use App\Models\IssueConversationMessage;
use App\Services\Ai\ChatService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Saved conversation endpoints for issues.
 *
 * Handles saving, listing, loading, and continuing saved AI conversations.
 * Authorization on all endpoints: IssuePolicy::view() via $this->authorize.
 *
 * @see task 09.01 / ADR-002
 */
class IssueConversationController extends Controller
{
    public function __construct(private readonly ChatService $chatService) {}

    /**
     * GET /api/issues/{issue}/conversations
     *
     * List saved conversations for an issue, newest first.
     * Includes message count and saved_by user name. Uses withCount to avoid N+1.
     */
    public function listConversations(Issue $issue): JsonResponse
    {
        $this->authorize('view', $issue);

        $conversations = IssueConversation::where('issue_id', $issue->id)
            ->with('savedBy:id,name')
            ->withCount('messages')
            ->latest()
            ->get();

        return response()->json([
            'data' => $conversations->map(fn (IssueConversation $c) => [
                'id' => $c->id,
                'title' => $c->title,
                'messages_count' => $c->messages_count,
                'saved_by' => [
                    'id' => $c->savedBy?->id,
                    'name' => $c->savedBy?->name,
                ],
                'created_at' => $c->created_at->toIso8601String(),
                'updated_at' => $c->updated_at->toIso8601String(),
            ]),
        ]);
    }

    /**
     * POST /api/issues/{issue}/conversations
     *
     * Save a conversation. Accepts title (optional) and messages array.
     * Uses DB transaction + bulk insert to keep it atomic and avoid N+1 inserts.
     */
    public function saveConversation(StoreConversationRequest $request, Issue $issue): JsonResponse
    {
        $this->authorize('view', $issue);

        $validated = $request->validated();

        $conversation = DB::transaction(function () use ($validated, $issue, $request): IssueConversation {
            $conversation = IssueConversation::create([
                'issue_id' => $issue->id,
                'saved_by' => $request->user()->id,
                'title' => $validated['title'] ?? null,
            ]);

            $now = now()->toDateTimeString();
            $rows = array_map(fn (array $msg) => [
                'conversation_id' => $conversation->id,
                'user_id' => $msg['role'] === 'user' ? $request->user()->id : null,
                'role' => $msg['role'],
                'content' => $msg['content'],
                'created_at' => $now,
                'updated_at' => $now,
            ], $validated['messages']);

            IssueConversationMessage::insert($rows);

            return $conversation;
        });

        $conversation->load('savedBy:id,name');
        $conversation->loadCount('messages');

        return response()->json([
            'data' => [
                'id' => $conversation->id,
                'title' => $conversation->title,
                'messages_count' => $conversation->messages_count,
                'saved_by' => [
                    'id' => $conversation->savedBy?->id,
                    'name' => $conversation->savedBy?->name,
                ],
                'created_at' => $conversation->created_at->toIso8601String(),
                'updated_at' => $conversation->updated_at->toIso8601String(),
            ],
        ], 201);
    }

    /**
     * GET /api/issues/{issue}/conversations/{conversation}
     *
     * Load a saved conversation with all its messages.
     */
    public function showConversation(Issue $issue, IssueConversation $conversation): JsonResponse
    {
        $this->authorize('view', $issue);

        $conversation->load([
            'savedBy:id,name',
            'messages' => fn ($q) => $q->orderBy('created_at'),
            'messages.user:id,name',
        ]);

        return response()->json([
            'data' => [
                'id' => $conversation->id,
                'title' => $conversation->title,
                'saved_by' => [
                    'id' => $conversation->savedBy?->id,
                    'name' => $conversation->savedBy?->name,
                ],
                'created_at' => $conversation->created_at->toIso8601String(),
                'updated_at' => $conversation->updated_at->toIso8601String(),
                'messages' => $conversation->messages->map(fn (IssueConversationMessage $m) => [
                    'id' => $m->id,
                    'role' => $m->role,
                    'content' => $m->content,
                    'user' => $m->user ? ['id' => $m->user->id, 'name' => $m->user->name] : null,
                    'created_at' => $m->created_at->toIso8601String(),
                ]),
            ],
        ]);
    }

    /**
     * POST /api/issues/{issue}/conversations/{conversation}/continue
     *
     * Append a new user message to a saved conversation and stream the AI response.
     * The user message is persisted BEFORE opening the stream (for durability).
     * The assistant response is persisted AFTER the stream completes.
     *
     * Returns 503 if AI provider is not configured.
     * Returns 429 if rate limited.
     */
    public function continueConversation(
        ContinueConversationRequest $request,
        Issue $issue,
        IssueConversation $conversation,
    ): StreamedResponse|JsonResponse {
        $this->authorize('view', $issue);

        if (! $this->chatService->isConfigured()) {
            return response()->json(['message' => 'AI provider is not configured.'], 503);
        }

        // Rate limit check (per user per issue)
        $key = 'chat:'.$request->user()->id.':'.$issue->id;

        if (RateLimiter::tooManyAttempts($key, 10)) {
            $seconds = RateLimiter::availableIn($key);

            return response()->json([
                'message' => 'Too many requests. Please wait '.$seconds.' seconds.',
            ], 429);
        }

        RateLimiter::hit($key, 3600);

        $validated = $request->validated();
        $newMessage = $validated['message'];

        // Persist user message BEFORE opening stream (durable even if stream errors)
        $userMessage = IssueConversationMessage::create([
            'conversation_id' => $conversation->id,
            'user_id' => $request->user()->id,
            'role' => 'user',
            'content' => $newMessage,
        ]);

        // Load conversation history from DB for context
        $conversation->load(['messages' => fn ($q) => $q->orderBy('created_at')]);
        $history = $conversation->messages
            ->filter(fn (IssueConversationMessage $m) => $m->id !== $userMessage->id)
            ->map(fn (IssueConversationMessage $m) => [
                'role' => $m->role,
                'content' => $m->content,
            ])
            ->values()
            ->toArray();

        return new StreamedResponse(function () use ($issue, $history, $newMessage, $conversation): void {
            $fullResponse = '';

            foreach ($this->chatService->streamChat($issue, $history, $newMessage) as $token) {
                // Check if this is an error token
                $decoded = json_decode($token, true);
                if (is_array($decoded) && isset($decoded['error'])) {
                    echo 'data: '.$token."\n\n";
                    echo "data: [DONE]\n\n";
                    flush();

                    return;
                }

                $fullResponse .= $token;
                echo 'data: '.json_encode(['token' => $token])."\n\n";
                flush();
            }

            echo "data: [DONE]\n\n";
            flush();

            // Persist the AI response after stream completes
            if (! empty($fullResponse)) {
                IssueConversationMessage::create([
                    'conversation_id' => $conversation->id,
                    'user_id' => null,
                    'role' => 'assistant',
                    'content' => $fullResponse,
                ]);

                $conversation->touch();
            }
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'X-Accel-Buffering' => 'no',
            'Connection' => 'keep-alive',
        ]);
    }
}
