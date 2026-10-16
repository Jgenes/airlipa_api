<?php

namespace App\Enums;

enum EvidenceType: string
{
    case DispatchProof = 'DISPATCH_PROOF';
    case CourierTracking = 'COURIER_TRACKING';
    case DeliveryOtpConfirmation = 'DELIVERY_OTP_CONFIRMATION';
    case DeliveryPhotoDoc = 'DELIVERY_PHOTO_DOC';
    case DigitalCompletion = 'DIGITAL_COMPLETION';
    case DisputeEvidence = 'DISPUTE_EVIDENCE';
}
