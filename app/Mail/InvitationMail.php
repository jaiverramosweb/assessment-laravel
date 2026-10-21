<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Invitation to answer an assessment: already rendered subject, plain text and HTML. */
class InvitationMail extends Mailable
{
    public function __construct(
        public readonly string $subjectLine,
        public readonly string $bodyText,
        public readonly string $bodyHtml,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectLine);
    }

    public function content(): Content
    {
        // The views read the public properties ($bodyHtml, $bodyText).
        return new Content(view: 'mail.invitation', text: 'mail.invitation-text');
    }
}
