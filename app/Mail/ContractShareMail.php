<?php

namespace App\Mail;

use App\Models\EscrowContract;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ContractShareMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public EscrowContract $contract,
        public string $message,
        public string $url
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: sprintf('Airlipa Escrow contract %s awaiting your acceptance', $this->contract->reference),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.contract-share',
            with: [
                'contract' => $this->contract,
                'message' => $this->message,
                'url' => $this->url,
            ],
        );
    }
}
