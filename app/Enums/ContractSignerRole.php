<?php

namespace App\Enums;

enum ContractSignerRole: string
{
    case Initiator = 'INITIATOR';
    case Counterparty = 'COUNTERPARTY';
}
