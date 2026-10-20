<?php

namespace App\Services;

use App\Enums\FulfillmentStatus;
use App\Enums\ProtectedPaymentStatus;
use App\Models\DeliveryConfirmationToken;
use App\Models\ProtectedPayment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * QR delivery-confirmation handshake (Module 5).
 *
 * The seller presents a single-use, expiring QR at handover; the buyer scans
 * it (or enters the reference), confirms the goods and the escrow moves into
 * "awaiting acceptance". This binds physical delivery to the digital record
 * without letting either party move money unilaterally.
 */
class DeliveryConfirmationService
{
    /**
     * Issue a single-use delivery confirmation token for a payment (seller/admin).
     *
     * @return array<string, mixed>
     */
    public function issue(ProtectedPayment $payment, User $actor): array
    {
        if ($actor->id !== $payment->merchant_id && !$actor->isAdmin()) {
            throw new AccessDeniedHttpException('Only the seller/merchant can issue a delivery confirmation code.');
        }

        if (!in_array($payment->status, [
            ProtectedPaymentStatus::FundedProtected,
            ProtectedPaymentStatus::FulfillmentInProgress,
            ProtectedPaymentStatus::PartiallyReleased,
        ], true)) {
            throw new ConflictHttpException(
                "A delivery confirmation code needs a funded agreement; this one is in state '{$payment->status->value}'."
            );
        }

        $plainToken = Str::random(40);
        $ttlMinutes = (int) config('protected_payment.delivery_confirmation_token_ttl_minutes', 1440);

        $token = DeliveryConfirmationToken::create([
            'protected_payment_id' => $payment->id,
            'token_hash' => hash('sha256', $plainToken),
            'created_by' => $actor->id,
            'purpose' => 'DELIVERY_CONFIRMATION',
            'status' => 'ACTIVE',
            'expires_at' => now()->addMinutes($ttlMinutes),
            'metadata' => ['reference' => $payment->reference],
        ]);

        AuditLogService::log('delivery_confirmation.issued', $actor, [
            'protected_payment_id' => $payment->protected_payment_id,
            'token_id' => $token->id,
            'expires_at' => $token->expires_at?->toIso8601String(),
        ]);

        return [
            'token' => $plainToken,
            'expires_at' => $token->expires_at?->toIso8601String(),
            'protected_payment_id' => $payment->protected_payment_id,
            'reference' => $payment->reference,
            'qr_payload' => 'airlipa://deliver?pp=' . urlencode($payment->protected_payment_id) . '&t=' . $plainToken,
            'instructions_en' => 'Show this code to the buyer at handover. The buyer scans it to confirm delivery inside Airlipa.',
            'instructions_sw' => 'Onyesha msimbo huu kwa mnunuaji wakati wa makabidhiano. Mnunuaji atasakani ili kuthibitisha kupokea bidhaa ndani ya Airlipa.',
        ];
    }

    /**
     * Buyer confirms delivery with a valid, unused, unexpired token.
     */
    public function confirm(ProtectedPayment $payment, User $actor, string $token): ProtectedPayment
    {
        if ($actor->id !== $payment->buyer_id && !$actor->isAdmin()) {
            throw new AccessDeniedHttpException('Only the buyer can confirm delivery of this agreement.');
        }

        return DB::transaction(function () use ($payment, $actor, $token): ProtectedPayment {
            $record = DeliveryConfirmationToken::where('protected_payment_id', $payment->id)
                ->where('status', 'ACTIVE')
                ->lockForUpdate()
                ->get()
                ->first(fn (DeliveryConfirmationToken $candidate): bool => hash_equals(
                    (string) $candidate->token_hash,
                    hash('sha256', $token)
                ));

            if (!$record) {
                throw new NotFoundHttpException('This delivery confirmation code is not valid for this agreement.');
            }

            if ($record->expires_at->isPast()) {
                $record->update(['status' => 'EXPIRED']);

                throw new ConflictHttpException('This delivery confirmation code has expired. Ask the seller to issue a new one.');
            }

            $record->update(['status' => 'CONSUMED', 'consumed_at' => now()]);

            $locked = ProtectedPayment::whereKey($payment->id)->lockForUpdate()->firstOrFail();

            $locked->update([
                'fulfillment_status' => FulfillmentStatus::DeliveredConfirmed,
                'status' => $locked->status === ProtectedPaymentStatus::FundedProtected
                    ? ProtectedPaymentStatus::AwaitingAcceptance
                    : $locked->status,
                'state_version' => (int) $locked->state_version + 1,
            ]);

            AuditLogService::log('delivery_confirmation.consumed', $actor, [
                'protected_payment_id' => $locked->protected_payment_id,
                'token_id' => $record->id,
            ]);

            return $locked->fresh(['feeQuote', 'merchant.businessProfile', 'buyer', 'milestones']);
        });
    }
}
