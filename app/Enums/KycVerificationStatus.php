<?php

namespace App\Enums;

enum KycVerificationStatus: string
{
    case Pending = 'Pending';
    case Verified = 'Verified';
    case Failed = 'Failed';
    case Locked = 'Locked';
}
