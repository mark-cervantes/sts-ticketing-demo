<?php

namespace App\Enums;

/**
 * Issue status lifecycle values.
 *
 * @see SPEC §4.2 / BR-01
 */
enum Status: string
{
    case Open = 'open';
    case InProgress = 'in_progress';
    case Resolved = 'resolved';
}
