<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class KybStatusUpdatedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        public User $user,
        public string $businessName,
        public string $status,
        public ?string $reason = null
    ) {
        $this->onConnection(config('auth_otp.mail.queue_connection', 'sync'));
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Business Verification Update - Airlipa',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.kyb_status_updated',
        );
    }
}
