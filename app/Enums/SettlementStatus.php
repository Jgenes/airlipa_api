<?php

namespace App\Enums;

enum SettlementStatus: string
{
    case NotEligible = 'NOT_ELIGIBLE';
    case Eligible = 'ELIGIBLE';
    case SettlementSubmitted = 'SETTLEMENT_SUBMITTED';
    case Settled = 'SETTLED';
    case Blocked = 'BLOCKED';
}
