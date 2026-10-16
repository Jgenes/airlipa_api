<?php

namespace App\Enums;

enum PaymentIntentStatus: string
{
    case Created = 'CREATED';
    case Submitted = 'SUBMITTED';
    case Accepted = 'ACCEPTED';
    case Pending = 'PENDING';
    case Unknown = 'UNKNOWN';
    case Succeeded = 'SUCCEEDED';
    case Failed = 'FAILED';
    case Expired = 'EXPIRED';
    case Cancelled = 'CANCELLED';
    case Reversed = 'REVERSED';
    case PartiallyRefunded = 'PARTIALLY_REFUNDED';
    case Refunded = 'REFUNDED';
}
