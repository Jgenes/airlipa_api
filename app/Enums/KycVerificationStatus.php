<?php

namespace App\Enums;

enum KycVerificationStatus: string
{
    case Pending = 'Pending';
    case Verified = 'Verified';

    /**
     * The challenge answers were submitted and did not match the NIDA record.
     * Only this status counts toward the 3-strike lockout window.
     */
    case Failed = 'Failed';

    /**
     * Superseded by a newer challenge for the same user. Abandoned, never answered.
     */
    case Cancelled = 'Cancelled';

    /**
     * The challenge window elapsed before answers were submitted.
     */
    case Expired = 'Expired';

    case Locked = 'Locked';
}
