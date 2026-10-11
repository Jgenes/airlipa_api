<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class LoginOtpMail extends Mailable
{
    public function __construct(
        public User $user,
        public string $code,
        public int $expiryMinutes = 5
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Your Airlipa Login Verification Code',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.login_otp',
        );
    }
}
