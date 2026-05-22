<?php

namespace App\Enums;

/**
 * Share permission levels — ladderized: view < comment < edit.
 *
 * @see SPEC §3.2 / SPEC §4.5
 */
enum Permission: string
{
    case View = 'view';
    case Comment = 'comment';
    case Edit = 'edit';

    /**
     * Whether this permission level allows posting comments.
     * True for Comment and Edit; false for View.
     */
    public function canComment(): bool
    {
        return $this === self::Comment || $this === self::Edit;
    }

    /**
     * Whether this permission level allows editing the issue.
     * True for Edit only.
     */
    public function canEdit(): bool
    {
        return $this === self::Edit;
    }
}
