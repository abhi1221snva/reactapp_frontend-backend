<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * Generic billing notification mailable.
 *
 * Used for: trial ending, payment failed, low wallet balance.
 * Each notification type maps to a different Blade template.
 */
class BillingNotificationMail extends Mailable
{
    use Queueable, SerializesModels;

    public string $recipientName;
    public string $companyName;
    public array $notificationData;

    public function __construct(
        string $template,
        string $subject,
        string $recipientName,
        string $companyName,
        array  $notificationData = []
    ) {
        $this->view            = $template;
        $this->subject         = $subject;
        $this->recipientName   = $recipientName;
        $this->companyName     = $companyName;
        $this->notificationData = $notificationData;
    }

    public function build(): static
    {
        return $this
            ->subject($this->subject)
            ->view($this->view)
            ->with([
                'recipientName'    => $this->recipientName,
                'companyName'      => $this->companyName,
                'notificationData' => $this->notificationData,
            ]);
    }
}
