<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class GenericMail extends Mailable
{
    use Queueable, SerializesModels;

    private $body;

    /**
     * Create a new message instance.
     *
     * @return void
     */
    public function __construct(string $subject, array $from, string $body)
    {
        $this->subject = $subject;
        $this->from = $from;
        $this->body = $body;
    }

    /**
     * Build the message.
     *
     * @return $this
     */
    public function build()
    {
        return $this->from($this->from["address"], $this->from["name"])
            ->subject($this->subject)
            ->view('emails.generic')->with([
                "subject" => $this->subject,
                "body" => $this->body,
            ]);
    }
}
