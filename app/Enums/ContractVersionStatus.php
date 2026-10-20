<?php

namespace App\Enums;

enum ContractVersionStatus: string
{
    case Current = 'CURRENT';
    case PendingInitiatorApproval = 'PENDING_INITIATOR_APPROVAL';
    case PendingCounterpartyApproval = 'PENDING_COUNTERPARTY_APPROVAL';
    case Superseded = 'SUPERSEDED';
}
