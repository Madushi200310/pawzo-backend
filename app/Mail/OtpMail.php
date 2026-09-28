<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class OtpMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $code,
        public string $type,
        public int $expiryMinutes,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->type === 'password_reset'
                ? 'Your Pawzo password reset code'
                : 'Verify your Pawzo account',
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.otp');
    }
}