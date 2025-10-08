<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;

class VerificationMail extends Mailable
{
    public $verificationCode;

    public function __construct($verificationCode)
    {
        $this->verificationCode = $verificationCode;
    }

    public function build()
    {
        return $this->subject('Verify Your Email Address')
            ->view('emails.verify')
            ->with([
                'code' => $this->verificationCode,
            ]);
    }
}
