<?php

namespace App\Enums;

enum EscrowFeePayer: string
{
    case Buyer = 'BUYER';
    case Seller = 'SELLER';
    case Split = 'SPLIT';
}
