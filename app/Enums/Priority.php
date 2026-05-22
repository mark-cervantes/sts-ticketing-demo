<?php

namespace App\Enums;

/**
 * Issue priority values with attention signalling.
 *
 * @see SPEC §4.2 / ADR-005 / BR-03
 */
enum Priority: string
{
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';
    case Critical = 'critical';

    /**
     * Whether this priority level alone triggers the needs_attention flag.
     * High and Critical always need attention regardless of deadline.
     */
    public function needsAttention(): bool
    {
        return $this === self::High || $this === self::Critical;
    }
}
