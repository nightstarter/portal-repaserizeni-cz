<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class OperationalAlertMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $checkKey,
        public readonly string $severity,
        public readonly string $lifecycle,
        public readonly string $event,
        public readonly string $summary,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: '[OPS '.$this->severity.'] '.$this->checkKey);
    }

    public function content(): Content
    {
        return new Content(text: 'emails.operational-alert');
    }
}
