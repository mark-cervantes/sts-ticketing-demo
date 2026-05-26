<?php

namespace App\Services\Ai\Tools;

/**
 * Value object returned by every ChatToolInterface::execute() call.
 *
 * When pendingConfirmation is true the action has NOT been performed yet —
 * the result is a pre-flight card for the frontend to render. The actual
 * execution happens at the tool-confirm endpoint after the user clicks "Create".
 *
 * @see task 09.04 / ChatToolInterface / IssueChatController::confirmTool()
 */
class ChatToolResult
{
    public function __construct(
        public readonly string $toolName,
        public readonly bool $success,
        public readonly string $message,
        /** @var array<string, mixed>|null */
        public readonly ?array $data = null,
        public readonly bool $pendingConfirmation = false,
    ) {}
}
