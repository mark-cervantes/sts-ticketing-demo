<?php

namespace App\Policies;

use App\Enums\Visibility;
use App\Models\Issue;
use App\Models\IssueShare;
use App\Models\User;

class IssuePolicy
{
    /**
     * Determine whether the user can view any models.
     *
     * Always true — any authenticated user may access the list endpoint.
     * The list is filtered by scopeAccessibleBy, not this policy.
     *
     * @see SRS §8.2 I-18
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can view the model.
     *
     * Owner → true; any share row → true; public → true; else false.
     *
     * @see ADR-004 §Access Resolution / ADR-007 §Access Resolution
     */
    public function view(User $user, Issue $issue): bool
    {
        if ($issue->user_id === $user->id) {
            return true;
        }

        if ($this->getShare($user, $issue) !== null) {
            return true;
        }

        if ($issue->visibility === Visibility::Public) {
            return true;
        }

        return false;
    }

    /**
     * Determine whether the user can create models.
     *
     * Any authenticated user may create an issue.
     *
     * @see SRS §8.2
     */
    public function create(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can update the model.
     *
     * Owner → true; share with edit permission → true; else false.
     *
     * @see ADR-007 §Access Resolution / Permission::canEdit()
     */
    public function update(User $user, Issue $issue): bool
    {
        if ($issue->user_id === $user->id) {
            return true;
        }

        $share = $this->getShare($user, $issue);

        return $share !== null && $share->permission->canEdit();
    }

    /**
     * Determine whether the user can comment on the model.
     *
     * Owner → true; share with comment or edit permission → true; else false.
     *
     * @see ADR-007 §Access Resolution / Permission::canComment()
     */
    public function comment(User $user, Issue $issue): bool
    {
        if ($issue->user_id === $user->id) {
            return true;
        }

        $share = $this->getShare($user, $issue);

        return $share !== null && $share->permission->canComment();
    }

    /**
     * Determine whether the user can delete the model.
     *
     * Owner only.
     *
     * @see SRS §8.2
     */
    public function delete(User $user, Issue $issue): bool
    {
        return $issue->user_id === $user->id;
    }

    /**
     * Determine whether the user can share the model.
     *
     * Owner only.
     *
     * @see ADR-007
     */
    public function share(User $user, Issue $issue): bool
    {
        return $issue->user_id === $user->id;
    }

    /**
     * Determine whether the user can restore the model.
     *
     * Owner only (for soft-deleted issues).
     *
     * @see SRS §8.2
     */
    public function restore(User $user, Issue $issue): bool
    {
        return $issue->user_id === $user->id;
    }

    /**
     * Determine whether the user can permanently delete the model.
     *
     * Owner only.
     */
    public function forceDelete(User $user, Issue $issue): bool
    {
        return $issue->user_id === $user->id;
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Fetch the share record for the given user on the given issue, if any.
     *
     * Centralises the DB lookup so each ability method calls it once.
     */
    private function getShare(User $user, Issue $issue): ?IssueShare
    {
        return $issue->shares()->where('user_id', $user->id)->first();
    }
}
