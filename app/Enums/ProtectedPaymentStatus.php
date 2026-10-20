<?php

namespace App\Enums;

enum ProtectedPaymentStatus: string
{
    case Draft = 'DRAFT';
    case AwaitingFunding = 'AWAITING_FUNDING';
    case PaymentProcessing = 'PAYMENT_PROCESSING';
    case FundedPendingPosting = 'FUNDED_PENDING_POSTING';
    case FundedProtected = 'FUNDED_PROTECTED';
    case FulfillmentInProgress = 'FULFILLMENT_IN_PROGRESS';
    case AwaitingAcceptance = 'AWAITING_ACCEPTANCE';
    case FulfillmentSubmitted = 'FULFILLMENT_SUBMITTED';
    case PartiallyReleased = 'PARTIALLY_RELEASED';
    case ReleaseApproved = 'RELEASE_APPROVED';
    case Released = 'RELEASED';
    case Disputed = 'DISPUTED';
    case RefundApproved = 'REFUND_APPROVED';
    case PartiallyRefunded = 'PARTIALLY_REFUNDED';
    case Refunded = 'REFUNDED';
    case Reversed = 'REVERSED';
    case Cancelled = 'CANCELLED';
    case Expired = 'EXPIRED';
    case Unknown = 'UNKNOWN';

    /**
     * Get the recommended customer-facing message per SASDS Section 5.2.1.
     *
     * @return array{en: string, sw: string}
     */
    public function customerMessages(): array
    {
        return match ($this) {
            self::Draft, self::AwaitingFunding => [
                'en' => 'Protected Payment agreement recorded. Awaiting customer payment authorization.',
                'sw' => 'Makubaliano ya Malipo Yanayolindwa yamerekodiwa. Inasubiri uidhinishaji wa malipo.',
            ],
            self::PaymentProcessing => [
                'en' => 'Your payment is being checked. Do not make another payment.',
                'sw' => 'Malipo yako yanahakikiwa. Usifanye malipo mengine tena.',
            ],
            self::Unknown => [
                'en' => 'We are verifying the payment with the provider. Do not pay again.',
                'sw' => 'Tunahakiki malipo na mtoa huduma. Usilipe tena.',
            ],
            self::FundedPendingPosting => [
                'en' => 'Payment was received by the provider. Protection setup is being completed.',
                'sw' => 'Malipo yamepokelewa na mtoa huduma. Usajili wa ulinzi unakamilishwa.',
            ],
            self::FundedProtected => [
                'en' => 'Payment is protected with the licensed payment provider. The merchant may now fulfil the order.',
                'sw' => 'Malipo yanalindwa kwa mtoa huduma aliyeidhinishwa. Mfanyabiashara anaweza kutekeleza huduma au mzigo sasa.',
            ],
            self::FulfillmentInProgress => [
                'en' => 'The seller is fulfilling the agreement. Your money stays protected until you confirm delivery.',
                'sw' => 'Muuzaji anatekeleza makubaliano. Pesa zako zinalindwa hadi uthibitishe kupokea bidhaa.',
            ],
            self::AwaitingAcceptance => [
                'en' => 'Delivery confirmed. Please accept the goods or open a dispute within the inspection window.',
                'sw' => 'Makabidhiano yamethibitishwa. Tafadhali kubali bidhaa au fungua malalamiko ndani ya muda wa ukaguzi.',
            ],
            self::FulfillmentSubmitted => [
                'en' => 'Fulfilment evidence has been submitted. Please review and confirm receipt or open a dispute within the inspection window.',
                'sw' => 'Ushahidi wa utekelezaji umewasilishwa. Tafadhali hakiki na uthibitishe kupokea au fungua malalamiko ndani ya muda wa ukaguzi.',
            ],
            self::Disputed => [
                'en' => 'The affected amount is under review and is not available for settlement.',
                'sw' => 'Kiasi husika kiko chini ya uchunguzi na hakipatikani kwa malipo ya mfanyabiashara.',
            ],
            self::ReleaseApproved => [
                'en' => 'Release is being processed by the payment provider.',
                'sw' => 'Kuruhusu malipo kunashughulikiwa na mtoa huduma wa malipo.',
            ],
            self::RefundApproved => [
                'en' => 'Refund is being processed by the payment provider.',
                'sw' => 'Urejeshaji wa fedha unashughulikiwa na mtoa huduma wa malipo.',
            ],
            self::PartiallyReleased => [
                'en' => 'A partial milestone release has been confirmed. Remaining funds stay protected.',
                'sw' => 'Sehemu ya malipo imeruhusiwa. Kiasi kilichobaki kinaendelea kulindwa.',
            ],
            self::Released => [
                'en' => 'The provider has confirmed the release.',
                'sw' => 'Mtoa huduma amethibitisha kuruhusiwa kwa malipo.',
            ],
            self::PartiallyRefunded, self::Refunded => [
                'en' => 'The provider has confirmed the refund.',
                'sw' => 'Mtoa huduma amethibitisha urejeshaji wa fedha.',
            ],
            self::Reversed => [
                'en' => 'The payment transaction was reversed by the licensed provider.',
                'sw' => 'Muamala wa malipo umebatilishwa na mtoa huduma aliyeidhinishwa.',
            ],
            self::Cancelled => [
                'en' => 'This Protected Payment agreement was cancelled before funding.',
                'sw' => 'Makubaliano haya ya Malipo Yanayolindwa yalisitishwa kabla ya kulipiwa.',
            ],
            self::Expired => [
                'en' => 'This Protected Payment agreement has expired.',
                'sw' => 'Muda wa makubaliano haya ya Malipo Yanayolindwa umeisha.',
            ],
        };
    }
}
