<?php

namespace App\Services;

use App\Model\Client\SmtpSetting;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mime\Email;
use Illuminate\Support\Facades\Log;
class MailService
{

    private $connection;

    private $mailable;

    private $smtpSetting;

    public function __construct(int $clientId, Mailable $mailable, SmtpSetting $smtpSetting)
    {
        $this->connection = ($clientId === 0 ? "master" : "mysql_$clientId");
        $this->mailable = $mailable;
        $this->smtpSetting = $smtpSetting;
    }
    function sendEmail($to)
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
        $transport = Transport::fromDsn($dsn);
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

    // function sendEmail($to)
    // {
    //     $transport = new \Swift_SmtpTransport($this->smtpSetting->mail_host, $this->smtpSetting->mail_port);
    //     $transport->setUsername($this->smtpSetting->mail_username);
    //     $transport->setPassword($this->smtpSetting->mail_password);
    //     $transport->setEncryption($this->smtpSetting->mail_encryption);

    //     $swift_mailer = new \Swift_Mailer($transport);
    //     $mailer = new Mailer("swift-mailer", view(), $swift_mailer);
    //     $from_email = empty($this->smtpSetting->from_email) ? env('DEFAULT_EMAIL') : $this->smtpSetting->from_email;
    //     $from_name = empty($this->smtpSetting->from_name) ? env('DEFAULT_NAME') : $this->smtpSetting->from_name;

    //     $mailer->alwaysFrom($from_email, $from_name);
    //     $mailer->alwaysReplyTo($from_email, $from_name);
    //     return $mailer->to($to)->send($this->mailable);
    // }

	function sendEmailWithAttachment($to,$attachment){
		$config = [
            'driver' => $this->smtpSetting->mail_driver,
            'host' => $this->smtpSetting->mail_host,
            'port' => $this->smtpSetting->mail_port,
            'encryption' => $this->smtpSetting->mail_encryption,
            'username' => $this->smtpSetting->mail_username,
            'password' => $this->smtpSetting->mail_password,
            'sendmail' => '/usr/sbin/sendmail -bs',
            'pretend' => false
        ];
        Config::set('mail', $config);



        return Mail::to($to)->send($this->mailable);
	}
}
