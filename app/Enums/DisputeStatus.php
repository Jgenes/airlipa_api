<?php

namespace App\Enums;

enum DisputeStatus: string
{
    case None = 'NONE';
    case OpenFrozen = 'OPEN_FROZEN';
    case UnderReview = 'UNDER_REVIEW';
    case ResolvedMerchant = 'RESOLVED_MERCHANT';
    case ResolvedBuyer = 'RESOLVED_BUYER';
    case ResolvedSplit = 'RESOLVED_SPLIT';
    case Appealed = 'APPEALED';
    case Closed = 'CLOSED';
}
