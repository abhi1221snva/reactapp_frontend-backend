<?php

namespace App\Services;

use App\Model\Client\SmtpSetting;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mime\Email;
use Illuminate\Support\Facades\Log;
class MailService
{

    private $connection;

    private $mailable;

    private $smtpSetting;

    public function __construct(int $clientId, $mailable, SmtpSetting $smtpSetting)
    {
        $this->connection = ($clientId === 0 ? "master" : "mysql_$clientId");
        $this->mailable = $mailable;
        $this->smtpSetting = $smtpSetting;
    }
    function sendEmail($to,array $cc = [], array $bcc = [], array $attachments = [])
{
    try {
        $smtp = $this->smtpSetting;

        // Build DSN (Dynamic SMTP connection string)
        $dsn = sprintf(
            'smtp://%s:%s@%s:%d?encryption=%s',
            urlencode($smtp->mail_username),
            urlencode($smtp->mail_password),
            $smtp->mail_host,
            $smtp->mail_port,
            $smtp->mail_encryption ?? 'tls'
        );

        // Create Symfony mail transport + mailer
        $transport = \Symfony\Component\Mailer\Transport::fromDsn($dsn);
        $mailer = new Mailer($transport);

        // Sender details
        $fromEmail = $smtp->from_email ?: env('DEFAULT_EMAIL');
        $fromName  = $smtp->from_name ?: env('DEFAULT_NAME');

        // Prepare email
        $email = (new Email())
            ->from(sprintf('%s <%s>', $fromName, $fromEmail))
            ->to($to)
            ->subject($this->mailable->subject ?? '(No Subject)')
            ->html($this->mailable->render());
  // ✅ Add CC
        if (!empty($cc)) {
            $email->cc(...$cc); // spread operator
        }

        // ✅ Add BCC
        if (!empty($bcc)) {
            $email->bcc(...$bcc);
        }

        // ✅ Add Attachments
        if (!empty($attachments)) {
            foreach ($attachments as $attachmentPath) {
                if (file_exists($attachmentPath)) {
                    $email->attachFromPath($attachmentPath);
                } else {
                    Log::warning("MailService: Attachment not found at path: $attachmentPath");
                }
            }
        }
        // Send
        $mailer->send($email);

        return true;

    } catch (\Throwable $e) {
        Log::error('MailService.sendEmail error', [
            'message' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
        ]);
        //return false;
         throw $e; // ✅ re-throw
    }
}




    function sendEmailWithAttachment($to, $attachmentPath)
{
    try {
        $smtp = $this->smtpSetting;

        $dsn = sprintf(
            'smtp://%s:%s@%s:%d?encryption=%s',
            urlencode($smtp->mail_username),
            urlencode($smtp->mail_password),
            $smtp->mail_host,
            $smtp->mail_port,
            $smtp->mail_encryption ?? 'tls'
        );
$transport = \Symfony\Component\Mailer\Transport::fromDsn($dsn);

        $mailer = new Mailer($transport);

        $email = (new Email())
            ->from(sprintf('%s <%s>', $smtp->from_name, $smtp->from_email))
            ->to($to)
            ->subject($this->mailable->subject)
            ->html($this->mailable->render())
            ->attachFromPath($attachmentPath);

        $mailer->send($email);

        return true;

    } catch (\Throwable $e) {
        Log::error('sendEmailWithAttachment error', [
            'msg' => $e->getMessage()
        ]);
        throw $e;
    }
}
}
