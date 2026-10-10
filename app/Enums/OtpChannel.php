<?php

namespace App\Enums;

enum OtpChannel: string
{
    case Email = 'email';
    case Sms = 'sms';
}
