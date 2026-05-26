<?php

namespace App\Models;

use Carbon\Carbon;
use Database\Factories\IssueConversationFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Saved AI conversation on an issue.
 *
 * Conversations are ephemeral during a session and only persisted when
 * the user explicitly saves them. Any viewer of the issue can see and
 * continue saved conversations.
 *
 * @property int $id
 * @property int $issue_id
 * @property int $saved_by
 * @property string|null $title
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Issue $issue
 * @property-read User $savedBy
 * @property-read Collection<int, IssueConversationMessage> $messages
 */
class IssueConversation extends Model
{
    /** @use HasFactory<IssueConversationFactory> */
    use HasFactory;

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'issue_id',
        'saved_by',
        'title',
    ];

    // -------------------------------------------------------------------------
    // Relationships
    // -------------------------------------------------------------------------

    /**
     * The issue this conversation belongs to.
     */
    public function issue(): BelongsTo
    {
        return $this->belongsTo(Issue::class);
    }

    /**
     * The user who saved this conversation.
     */
    public function savedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'saved_by');
    }

    /**
     * The messages in this conversation.
     */
    public function messages(): HasMany
    {
        return $this->hasMany(IssueConversationMessage::class, 'conversation_id');
    }
}
