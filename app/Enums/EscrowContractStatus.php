<?php

namespace App\Enums;

enum EscrowContractStatus: string
{
    case Draft = 'DRAFT';
    case Shared = 'SHARED';
    case Accepted = 'ACCEPTED';
    case Rejected = 'REJECTED';
    case Expired = 'EXPIRED';
    case Cancelled = 'CANCELLED';

    public function allowsEditing(): bool
    {
        return $this === self::Draft;
    }

    public function allowsSharing(): bool
    {
        return in_array($this, [self::Draft, self::Shared], true);
    }

    public function allowsAcceptance(): bool
    {
        return $this === self::Shared;
    }

    public function allowsCancellation(): bool
    {
        return in_array($this, [self::Draft, self::Shared], true);
    }

    /**
     * Customer-facing lifecycle message (Swahili + English, mobile-first UI).
     *
     * @return array{en: string, sw: string}
     */
    public function customerMessages(): array
    {
        return match ($this) {
            self::Draft => [
                'en' => 'Contract draft saved. Share it with the counterparty when ready.',
                'sw' => 'Rasimu ya mkataba imehifadhiwa. Ishiriki na upande wa pili inapokuwa tayari.',
            ],
            self::Shared => [
                'en' => 'Contract shared and awaiting counterparty acceptance.',
                'sw' => 'Mkataba umeshirikiwa unasubiri ukubali wa upande wa pili.',
            ],
            self::Accepted => [
                'en' => 'Contract accepted by both parties. Proceed to deposit into escrow.',
                'sw' => 'Mkataba umekubaliwa na pande zote. Endelea kuweka amana kwenye eskrow.',
            ],
            self::Rejected => [
                'en' => 'The counterparty rejected this contract.',
                'sw' => 'Upande wa pili umekataa mkataba huu.',
            ],
            self::Expired => [
                'en' => 'This contract expired before it was accepted.',
                'sw' => 'Mkataba huu umepita muda kabla ya kukubaliwa.',
            ],
            self::Cancelled => [
                'en' => 'This contract was cancelled by its initiator.',
                'sw' => 'Mkataba huu umesitishwa na mwanzilishi wake.',
            ],
        };
    }
}
