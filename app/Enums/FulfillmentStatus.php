<?php

namespace App\Enums;

enum FulfillmentStatus: string
{
    case Pending = 'PENDING';
    case InProgress = 'IN_PROGRESS';
    case EvidenceSubmitted = 'EVIDENCE_SUBMITTED';
    case DeliveredConfirmed = 'DELIVERED_CONFIRMED';
    case Failed = 'FAILED';
    case Returned = 'RETURNED';
}
