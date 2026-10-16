<?php

namespace App\Enums;

enum DeliveryMethod: string
{
    case Courier = 'COURIER';
    case InPersonOtp = 'IN_PERSON_OTP';
    case DigitalHandover = 'DIGITAL_HANDOVER';
    case Milestone = 'MILESTONE';
}
