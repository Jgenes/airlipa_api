<?php

namespace App\Enums;

enum RefundRequestStatus: string
{
    case Requested = 'REQUESTED';
    case Succeeded = 'SUCCEEDED';
    case Failed = 'FAILED';
    case Reversed = 'REVERSED';
}
