<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ContractAcceptanceOtpMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public User $user,
        public string $code,
        public int $expiryMinutes
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Airlipa escrow contract acceptance code / Msimbo wa kukubali mkataba',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.contract-acceptance-otp',
            with: [
                'user' => $this->user,
                'code' => $this->code,
                'expiryMinutes' => $this->expiryMinutes,
            ],
        );
    }
}
