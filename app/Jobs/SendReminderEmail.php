<?php

namespace App\Jobs;
use App\Model\Client\CrmScheduledTask;
use App\Services\CrmMailService;
use Carbon\Carbon;
use App\Model\Client\EmailSetting;
use Illuminate\Support\Facades\DB;
use App\Model\User;
class SendReminderEmail extends Job
{
    /**
     * Create a new job instance.
     *
     * @return void
     */
    private $clientId;

    public function __construct(int $clientId)
    {
        $this->clientId = $clientId;

    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        $now = Carbon::now();
        // $clientId=3;
        $connectionName = 'mysql_' . $this->clientId;
        //dd($connectionName);
        // $connectionName = "mysql_$clientId";
        $tasks = CrmScheduledTask::on($connectionName)
            ->whereDate('date', $now->toDateString()) // Match today's date
            ->where('is_sent', false) // Only unsent tasks
            ->whereTime('time', '>=', Carbon::now()->format('H:i:s')) // Tasks scheduled for now or later
            ->get();
        
        //dd($tasks);
        foreach ($tasks as $task) {
            // Fetch SMTP settings
            $smtp_setting = EmailSetting::on(($connectionName))
                ->where('mail_type', 'notification')
                ->first();
//dd($smtp_setting);

            // Prepare email data
            $user = User::findOrFail($task->user_id);
            //dd($user->email);
            $subject = 'Reminder - Lead Id: ' . $task->lead_id;
            $message = $user->first_name . ' ' . $user->last_name . ' - <b>Added a reminder for</b>: ' . $task->task_name . ' on ' . Carbon::parse($task->date)->format('m-d-Y') . ' at ' . $task->time;
            //dd($message)
            $data = ['subject' => $subject, 'content' => $message];
            $mailable ="emails.crm-generic";
            
            // Send the email
        try {
            $mailService = new CrmMailService($this->clientId, $mailable, $smtp_setting, $data);
            //dd($mailService);

            $isSent = $mailService->sendEmail([$user->email]);
//dd($isSent);
            if ($isSent) {
                // Mark the task as sent
                $task->update(['is_sent' => true]);
                \Log::info("Email sent successfully to {$user->email}");
            } else {
                \Log::warning("Failed to send email to {$user->email} without errors.");
            }
        } catch (\Exception $e) {
            $errorMessage = $e->getMessage();
            \Log::error("Error sending email to {$user->email}: {$errorMessage}");
            // Log the error for debugging purposes
            \Log::error("Error sending email for Task ID {$task->id}: {$errorMessage}");
        }
    
        }

        \Log::info('Scheduled reminders processed successfully.');
    }
}
