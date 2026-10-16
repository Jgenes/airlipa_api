<?php

namespace App\Enums;

enum DisputeCaseType: string
{
    case NonDelivery = 'NON_DELIVERY';
    case MateriallyDifferent = 'MATERIALLY_DIFFERENT';
    case PartialDelivery = 'PARTIAL_DELIVERY';
    case UnauthorizedPayment = 'UNAUTHORIZED_PAYMENT';
    case ProviderComplaint = 'PROVIDER_COMPLAINT';
    case FraudInvestigation = 'FRAUD_INVESTIGATION';
}
