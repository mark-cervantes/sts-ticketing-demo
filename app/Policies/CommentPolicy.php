<?php

namespace App\Policies;

use App\Models\Issue;
use App\Models\User;

class CommentPolicy
{
    /**
     * Determine whether the user can create a comment on the given issue.
     *
     * Delegates entirely to IssuePolicy::comment — keeping authorization
     * for "can this user comment?" in a single place.
     *
     * Called via $user->can('create', [Comment::class, $issue]).
     *
     * @see SRS §8.2 / IssuePolicy::comment
     */
    public function create(User $user, Issue $issue): bool
    {
        return $user->can('comment', $issue);
    }
}
