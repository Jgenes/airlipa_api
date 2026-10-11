<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class KybStatusUpdatedMail extends Mailable
{
    public function __construct(
        public User $user,
        public string $businessName,
        public string $status,
        public ?string $reason = null
    ) {}

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
