<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Lang;

class DiscussionMessageNotificationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $orderNumber,
        public readonly string $discussionUrl,
        public readonly string $recipientLocale,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: Lang::get('app.discussion_email_subject', ['order' => $this->orderNumber], $this->recipientLocale),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.discussion-message',
            with: [
                'locale' => $this->recipientLocale,
                'orderNumber' => $this->orderNumber,
                'discussionUrl' => $this->discussionUrl,
            ],
        );
    }
}
