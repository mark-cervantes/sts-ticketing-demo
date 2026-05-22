<?php

namespace App\Enums;

/**
 * AI summary generation lifecycle values.
 *
 * @see SPEC §4.2
 */
enum SummaryStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Ready = 'ready';
    case Failed = 'failed';
}
