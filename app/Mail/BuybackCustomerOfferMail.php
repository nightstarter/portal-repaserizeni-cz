<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class BuybackCustomerOfferMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $orderNo,
        public readonly string $formattedAmount,
        public readonly string $currency,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('app.buyback_email_subject', ['order' => $this->orderNo]));
    }

    public function content(): Content
    {
        return new Content(view: 'emails.buyback-customer-offer');
    }
}
