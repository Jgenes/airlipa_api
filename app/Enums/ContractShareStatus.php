<?php

namespace App\Enums;

enum ContractShareStatus: string
{
    case Created = 'CREATED';
    case Delivered = 'DELIVERED';
    case Failed = 'FAILED';
    case Opened = 'OPENED';
}
