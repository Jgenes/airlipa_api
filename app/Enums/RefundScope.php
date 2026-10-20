<?php

namespace App\Enums;

enum RefundScope: string
{
    case Full = 'FULL';
    case Partial = 'PARTIAL';
    case Milestone = 'MILESTONE';
}
