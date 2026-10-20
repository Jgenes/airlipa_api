<?php

namespace App\Services;

use App\Enums\ContractShareChannel;
use App\Enums\ContractShareStatus;
use App\Mail\ContractShareMail;
use App\Models\ContractShare;
use App\Models\EscrowContract;
use App\Services\Sms\SmsServiceInterface;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\GoneHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Multi-channel agreement sharing (WhatsApp / SMS / Email / QR / secure link).
 *
 * Every channel carries the same signed, expiring, single-contract token. The
 * counterparty can view the contract anonymously but must register and verify
 * their phone via OTP before acceptance (handled by EscrowContractService).
 */
class ContractShareService
{
    public function __construct(
        protected SmsServiceInterface $smsService
    ) {}

    /**
     * @param array<int, string> $channels
     * @return array<int, ContractShare>
     */
    public function createShares(EscrowContract $contract, array $channels, ?string $customMessage = null): array
    {
        $shares = [];

        foreach ($channels as $channelValue) {
            $channel = ContractShareChannel::from(strtoupper((string) $channelValue));

            if (!in_array($channel->value, config('escrow_contract.enabled_channels', []), true)) {
                throw new HttpException(422, "Share channel {$channel->value} is currently disabled.");
            }

            $recipient = match ($channel) {
                ContractShareChannel::Whatsapp, ContractShareChannel::Sms => $contract->counterparty_phone,
                ContractShareChannel::Email => $contract->counterparty_email,
                default => null,
            };

            if ($channel->requiresRecipient() && empty($recipient)) {
                throw new HttpException(422, "Channel {$channel->value} requires a counterparty " . ($channel === ContractShareChannel::Email ? 'email' : 'phone') . ' on the contract.');
            }

            [$token, $expiresAt] = $this->issueToken($contract->id);
            $url = $this->publicUrl($token);
            $message = $customMessage ?: $this->defaultMessage($contract, $url);

            $payload = ['url' => $url, 'message' => $message];
            $status = ContractShareStatus::Created;
            $deliveredAt = null;
            $failureReason = null;

            switch ($channel) {
                case ContractShareChannel::Whatsapp:
                    $digits = preg_replace('/\D+/', '', (string) $recipient);
                    $payload['whatsapp_url'] = 'https://wa.me/' . $digits . '?text=' . rawurlencode($message);
                    $status = ContractShareStatus::Delivered;
                    $deliveredAt = now();
                    break;

                case ContractShareChannel::Sms:
                    $sent = false;
                    try {
                        $sent = $this->smsService->send((string) $recipient, $message);
                    } catch (\Throwable $exception) {
                        Log::warning('contract_share_sms_failed', [
                            'contract_id' => $contract->contract_id,
                            'error' => $exception->getMessage(),
                        ]);
                    }
                    $status = $sent ? ContractShareStatus::Delivered : ContractShareStatus::Failed;
                    $deliveredAt = $sent ? now() : null;
                    $failureReason = $sent ? null : 'sms_gateway_rejected';
                    break;

                case ContractShareChannel::Email:
                    try {
                        Mail::to((string) $recipient)->send(new ContractShareMail($contract, $message, $url));
                        $status = ContractShareStatus::Delivered;
                        $deliveredAt = now();
                    } catch (\Throwable $exception) {
                        Log::warning('contract_share_email_failed', [
                            'contract_id' => $contract->contract_id,
                            'error' => $exception->getMessage(),
                        ]);
                        $status = ContractShareStatus::Failed;
                        $failureReason = 'mail_transport_rejected';
                    }
                    break;

                case ContractShareChannel::Qr:
                    $payload['qr_payload'] = $url;
                    break;

                case ContractShareChannel::Link:
                    break;
            }

            $shares[] = ContractShare::create([
                'share_id' => 'csh_' . Str::lower(Str::ulid()),
                'escrow_contract_id' => $contract->id,
                'version' => (int) $contract->current_version,
                'channel' => $channel,
                'recipient' => $recipient,
                'token' => $token,
                'token_expires_at' => $expiresAt,
                'status' => $status,
                'delivered_at' => $deliveredAt,
                'payload' => $payload,
                'failure_reason' => $failureReason,
            ]);
        }

        return $shares;
    }

    /**
     * Signed, expiring, single-contract token: {contractId}.{expiryUnix}.{hmac}.
     *
     * @return array{0: string, 1: \Illuminate\Support\Carbon}
     */
    public function issueToken(int $contractId): array
    {
        $expiresAt = now()->addHours((int) config('escrow_contract.share_token_ttl_hours', 72));
        $body = $contractId . '.' . $expiresAt->getTimestamp();

        return [$body . '.' . hash_hmac('sha256', $body, $this->signingKey()), $expiresAt];
    }

    /**
     * Verify signature + expiry and load the share row.
     */
    public function resolveToken(string $token): ContractShare
    {
        $parts = explode('.', $token);

        if (count($parts) !== 3) {
            throw new NotFoundHttpException('Contract share link not found.');
        }

        [$contractId, $expiry, $signature] = $parts;
        $expected = hash_hmac('sha256', $contractId . '.' . $expiry, $this->signingKey());

        if (!hash_equals($expected, $signature)) {
            throw new NotFoundHttpException('Contract share link not found.');
        }

        if ((int) $expiry < now()->getTimestamp()) {
            throw new GoneHttpException('This contract link has expired. Ask the initiator to share a fresh link.');
        }

        $share = ContractShare::where('token', $token)->first();

        if (!$share) {
            throw new NotFoundHttpException('Contract share link not found.');
        }

        if ($share->isTokenExpired()) {
            throw new GoneHttpException('This contract link has expired. Ask the initiator to share a fresh link.');
        }

        return $share;
    }

    /**
     * Track delivered/opened/viewed status per channel.
     */
    public function recordView(ContractShare $share): ContractShare
    {
        $share->view_count = (int) $share->view_count + 1;
        $share->last_viewed_at = now();
        $share->opened_at = $share->opened_at ?? now();

        if (in_array($share->status, [ContractShareStatus::Created, ContractShareStatus::Delivered], true)) {
            $share->status = ContractShareStatus::Opened;
        }

        $share->save();

        return $share;
    }

    public function publicUrl(string $token): string
    {
        return rtrim((string) config('escrow_contract.public_base_url'), '/') . '/' . $token;
    }

    protected function signingKey(): string
    {
        return (string) (config('escrow_contract.token_secret') ?: config('app.key'));
    }

    protected function defaultMessage(EscrowContract $contract, string $url): string
    {
        $amount = number_format(((int) $contract->amount_minor) / 100, 2);

        return sprintf(
            'Airlipa Escrow: %s (ref %s) - TZS %s. %s. Review and accept securely: %s',
            $contract->title,
            $contract->reference,
            $amount,
            $contract->initiator_role === 'BUYER' ? 'You are the seller in this agreement' : 'You are the buyer in this agreement',
            $url
        );
    }
}
