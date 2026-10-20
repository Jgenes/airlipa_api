<?php

namespace App\Enums;

enum ContractShareChannel: string
{
    case Whatsapp = 'WHATSAPP';
    case Sms = 'SMS';
    case Email = 'EMAIL';
    case Qr = 'QR';
    case Link = 'LINK';

    public function requiresRecipient(): bool
    {
        return in_array($this, [self::Whatsapp, self::Sms, self::Email], true);
    }
}
