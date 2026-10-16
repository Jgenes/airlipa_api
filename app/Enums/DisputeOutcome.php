<?php

namespace App\Enums;

enum DisputeOutcome: string
{
    case FullRefundBuyer = 'FULL_REFUND_BUYER';
    case FullReleaseMerchant = 'FULL_RELEASE_MERCHANT';
    case PartialSplit = 'PARTIAL_SPLIT';
}
