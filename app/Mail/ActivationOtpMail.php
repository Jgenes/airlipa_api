<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ActivationOtpMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        public User $user,
        public string $code,
        public int $expiryMinutes = 10
    ) {
        $this->onConnection(config('auth_otp.mail.queue_connection', 'sync'));
    }

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
