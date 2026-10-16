<?php

namespace App\Enums;

enum TransactionType: string
{
    case ProtectedPayment = 'PROTECTED_PAYMENT';
    case DirectPayment = 'DIRECT_PAYMENT';
}
