<?php

namespace App\Models;

use Carbon\Carbon;
use Database\Factories\IssueConversationMessageFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single message in a saved AI conversation.
 *
 * user_id is null for assistant (AI) messages.
 * role is 'user' or 'assistant'.
 *
 * @property int $id
 * @property int $conversation_id
 * @property int|null $user_id
 * @property string $role
 * @property string $content
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read IssueConversation $conversation
 * @property-read User|null $user
 */
class IssueConversationMessage extends Model
{
    /** @use HasFactory<IssueConversationMessageFactory> */
    use HasFactory;

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'conversation_id',
        'user_id',
        'role',
        'content',
    ];

    // -------------------------------------------------------------------------
    // Relationships
    // -------------------------------------------------------------------------

    /**
     * The conversation this message belongs to.
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(IssueConversation::class, 'conversation_id');
    }

    /**
     * The user who sent this message (null for AI responses).
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
