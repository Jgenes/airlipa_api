<?php

namespace App\Enums;

enum BusinessProfileStatus: string
{
    case Pending = 'Pending';
    case Verified = 'Verified';
    case Rejected = 'Rejected';
}
