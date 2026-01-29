<?php

namespace App\Mail;
class GenericMail
{
    public $subject;
    public $from;
    public $body;

    public function __construct(string $subject, array $from, string $body)
    {
        $this->subject = $subject;
        $this->from = $from;
        $this->body = $body;
    }

    public function render(): string
    {
        // Render body manually or load a view:
        return view('emails.generic', [
            'subject' => $this->subject,
            'body' => $this->body,
        ])->render();
    }
}

