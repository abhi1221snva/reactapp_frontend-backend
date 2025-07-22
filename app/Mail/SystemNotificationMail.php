<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class SystemNotificationMail extends Mailable
{
    use Queueable, SerializesModels;

    public $from;
    public $subject;
    public $view;
    private $data;

    /**
     * Create a new message instance.
     * @param array $from
     * @param string $view
     * @param string $subject
     * @param array $data
     */
    public function __construct(array $from, string $view, string $subject, array $data , string $file_path='')
    {
        $this->from = $from;
        $this->view = $view;
        $this->subject = $subject;
        $this->data = $data;
		$this->file_path = $file_path;
    }

    /**
     * Build the message.
     *
     * @return $this
     */
    public function build()
    {
        $mailObj = $this->from($this->from["address"], $this->from["name"]);
        $mailObj->subject($this->subject);
        if(!empty($this->file_path)){
            $mailObj->attach( $this->file_path, [ 'as' => time().'.pdf', 'mime' => 'application/pdf']);
        }
        return $mailObj->view($this->view)->with([ "subject" => $this->subject, "data" => $this->data]);


    }
}
