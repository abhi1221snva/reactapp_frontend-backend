<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class LenderApplicationMail extends Mailable
{
    use Queueable, SerializesModels;

    public string $businessName;
    public string $senderName;
    public ?string $customNote;
    public string $companyName;

    /**
     * @param string      $businessName Name of the business / lead
     * @param string      $senderName   Name of the CRM user sending
     * @param string|null $pdfPath      Absolute filesystem path to the application PDF (null = no attachment)
     * @param string|null $pdfFileName  Display name for the attachment
     * @param string|null $customNote   Optional personal note to include in the body
     * @param string|null $companyName  Client's company name (replaces "RocketDialer CRM" branding)
     */
    public function __construct(
        string  $businessName,
        string  $senderName,
        private ?string $pdfPath     = null,
        private ?string $pdfFileName = null,
        ?string $customNote          = null,
        ?string $companyName         = null,
    ) {
        $this->businessName = $businessName;
        $this->senderName   = $senderName;
        $this->customNote   = $customNote;
        $this->companyName  = $companyName ?: 'Our Team';
    }

    public function build(): static
    {
        $mail = $this
            ->subject("New Funding Application — {$this->businessName}")
            ->view('emails.lender_application');

        if ($this->pdfPath && file_exists($this->pdfPath)) {
            $mail->attach($this->pdfPath, [
                'as'   => $this->pdfFileName ?? 'application.pdf',
                'mime' => 'application/pdf',
            ]);
        }

        return $mail;
    }
}
