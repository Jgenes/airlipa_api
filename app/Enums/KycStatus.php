<?php

namespace App\Enums;

enum KycStatus: string
{
    case NotStarted = 'Not_Started';
    case Verified = 'Verified';
    case Failed = 'Failed';
}
