<?php

namespace App\Services;

//use App\Model\Client\SmtpSetting;
use App\Model\Client\EmailSetting;

use Illuminate\Mail\Mailer;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Mail;
use Swift_Attachment;
use Illuminate\Support\Facades\Log;


class CrmMailService
{

    private $connection;
    private $mailable;
    private $smtpSetting;

    public function __construct(int $clientId, $mailable, EmailSetting $smtpSetting,$data_array)
    {
        $this->connection = ($clientId === 0 ? "master" : "mysql_$clientId");
        $this->mailable = $mailable;
        $this->smtpSetting = $smtpSetting;
        $this->data_array = $data_array;

    }

    function sendEmail($emails)
    {
        $transport = new \Swift_SmtpTransport($this->smtpSetting->mail_host, $this->smtpSetting->mail_port);
        $transport->setUsername($this->smtpSetting->mail_username);
        $transport->setPassword($this->smtpSetting->mail_password);
        $transport->setEncryption($this->smtpSetting->mail_encryption);
        $mailer = new \Swift_Mailer($transport);
        $data=array();
        $message = (new \Swift_Message($this->data_array['subject']));
        $message->setFrom([$this->smtpSetting->sender_email => $this->smtpSetting->sender_name]);
        //$message->setTo([$to => $this->smtpSetting->sender_name]);
        $message ->setBody(view($this->mailable,$this->data_array)->render(),'text/html');
        if (!is_array($emails)) {
            $emails = (array) $emails; // Convert single email or null to an array
        }
        
        try {
            // echo "<pre>";print_r($emails);die;
            foreach ($emails as $key => $to_email) {
                //echo $to_email;die;
                $message->setTo($to_email);
                $result = $mailer->send($message);
            }

                Log::info("Email sent successfully to: ");
            } catch (\Exception $e) {
                Log::error("Error sending email to: " . " Error: " . $e->getMessage());
            }

           

       
        

        //$result = $mailer->send($message);
    }

    // function sendEmailAttachment($emails,$file_paths)
    // {
    //     $transport = new \Swift_SmtpTransport($this->smtpSetting->mail_host, $this->smtpSetting->mail_port);
    //     $transport->setUsername($this->smtpSetting->mail_username);
    //     $transport->setPassword($this->smtpSetting->mail_password);
    //     $transport->setEncryption($this->smtpSetting->mail_encryption);
    //     $mailer = new \Swift_Mailer($transport);
    //     $data=array();
    //     $message = (new \Swift_Message($this->data_array['subject']));
    //     $message->setFrom([$this->smtpSetting->sender_email => $this->smtpSetting->sender_name]);
    //     //$message->setTo([$to => $this->smtpSetting->from_name,'abhi4mca@gmail.com']);

    //     $message ->setBody(view($this->mailable,$this->data_array)->render(),'text/html');

    //     Log::error("fileLog.sendMail.error",["file" => $file_paths]);

    //     foreach ($file_paths as $key => $value) {
    //         $message->attach(Swift_Attachment::fromPath($value));
    //     }
        
    //     Log::error("fileLog1.sendMail.error",["file" => $message]);

    //     foreach ($emails as $key => $to_email) {
    
    //         $message->setTo($to_email);
    //         $result = $mailer->send($message);
    //     }
    // }

    function sendEmailAttachment($emails, $file_paths)
    {
        // Set up the Swift Mailer transport
        $transport = new \Swift_SmtpTransport($this->smtpSetting->mail_host, $this->smtpSetting->mail_port);
        $transport->setUsername($this->smtpSetting->mail_username);
        $transport->setPassword($this->smtpSetting->mail_password);
        $transport->setEncryption($this->smtpSetting->mail_encryption);
        $mailer = new \Swift_Mailer($transport);
    
        // Create attachments
        $attachments = [];
        foreach ($file_paths as $file_path) {
            $attachments[] = \Swift_Attachment::fromPath($file_path);
        }
    
        // Ensure that $emails and $ccEmailsList are arrays
        $emails = is_array($emails) ? $emails : [];
       // $ccEmailsList = is_array($ccEmailsList) ? $ccEmailsList : [];
    
        // Log input data for debugging
        Log::info("Emails: " . json_encode($emails));
        //Log::info("CC Emails List: " . json_encode($ccEmailsList));
    
        // Create a map of emails to CC emails
        /*$emailCcMap = [];
        $ccCount = count($ccEmailsList);
        $emailCount = count($emails);
    
        // Determine the number of CCs per email
        $ccPerEmail = ceil($ccCount / $emailCount);
        $currentCcIndex = 0;*/
    /*
        foreach ($emails as $index => $email) {
            // Assign CCs to the email
            $emailCcMap[$email] = array_slice($ccEmailsList, $currentCcIndex, $ccPerEmail);
            $currentCcIndex += $ccPerEmail;
        }
    
        // Log the email-CC mapping
        Log::info("Email-CC Map: " . json_encode($emailCcMap));*/
    
        // Send an individual email to each recipient

       // echo "<pre>";print_r($emails);die;

        foreach ($emails as $to_email) {
            // Create a new message object for each recipient
            $message = (new \Swift_Message($this->data_array['subject']))
                ->setFrom([$this->smtpSetting->sender_email => $this->smtpSetting->sender_name])
                ->setBody(view($this->mailable, $this->data_array)->render(), 'text/html');
    
            // Attach files
            foreach ($attachments as $attachment) {
                $message->attach($attachment);
            }

            $emailTo = $to_email['to'];

            $cc = array();



    
            // Set the 'To' for the recipient
            $message->setTo($emailTo);
            Log::info("Sending email to: " . $emailTo . ": " . json_encode($emailTo));

    
            // Assign the correct CC for the current email if it exists
            //$ccEmailsForRecipient = isset($emailCcMap[$to_email]) ? (array) $emailCcMap[$to_email] : [];

            if(isset($to_email['cc1']))
            {
                $cc[0] = $to_email['cc1'];
            }

            if(isset($to_email['cc2']))
            {
                $cc[1] = $to_email['cc2'];
            }
            if(isset($to_email['cc3']))
            {
                $cc[2] = $to_email['cc3'];
            }
            if(isset($to_email['cc4']))
            {
                $cc[3] = $to_email['cc4'];
            }

           // echo "<pre>";print_r($cc);die;
            


           // $cc = array($to_email['cc1'],$to_email['cc2']);


            /*if(isset($to_email['cc1']))
            {
            $ccEmailsForRecipient = $to_email['cc1'];*/
            $message->setCc($cc);
            Log::info("Sending email to:  with CC: " . json_encode($cc));


           // }


           /* if(isset($to_email['cc2']))
            {
            $ccEmailsForRecipient1 = $to_email['cc2'];

            $message->setCc($ccEmailsForRecipient1);
            Log::info("Sending email to: " . $to_email['cc2'] . " with CC: " . json_encode($ccEmailsForRecipient1));

                
            }*/


             
    
            // Log and send the message
            try {
                $result = $mailer->send($message);
                Log::info("Email sent successfully to: " . $emailTo . " with CC: ");
            } catch (\Exception $e) {
                Log::error("Error sending email to: " . $emailTo . " Error: " . $e->getMessage());
            }
        }
    }
    
    

    
    

  
    


}
