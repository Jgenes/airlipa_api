<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class ActivationOtpMail extends Mailable
{
    public function __construct(
        public User $user,
        public string $code,
        public int $expiryMinutes = 10
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Activate Your Airlipa Account',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.activation_otp',
        );
    }
}
