<?php

namespace App\Enums;

enum PaymentLinkStatus: string
{
    case Active = 'ACTIVE';
    case Expired = 'EXPIRED';
    case Revoked = 'REVOKED';
    case Exhausted = 'EXHAUSTED';
}
