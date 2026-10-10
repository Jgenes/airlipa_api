<?php

namespace App\Enums;

enum KybStatus: string
{
    case NotStarted = 'Not_Started';
    case Pending = 'Pending';
    case Verified = 'Verified';
    case Rejected = 'Rejected';
}
