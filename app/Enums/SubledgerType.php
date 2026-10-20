<?php

namespace App\Enums;

enum SubledgerType: string
{
    case Contract = 'CONTRACT';
    case Milestone = 'MILESTONE';
    case Party = 'PARTY';
    case DisputeHold = 'DISPUTE_HOLD';
    case Fee = 'FEE';
    case Payout = 'PAYOUT';
    case Refund = 'REFUND';
}
