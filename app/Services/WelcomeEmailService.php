<?php

namespace App\Services;

use App\Mail\SystemNotificationMail;
use App\Model\Client\SmtpSetting;
use Illuminate\Support\Facades\Log;

/**
 * Sends welcome and onboarding emails after user registration.
 */
class WelcomeEmailService
{
    /**
     * Send a welcome email to a newly registered user.
     *
     * @param  string $email
     * @param  string $name
     * @param  string $loginUrl  URL of the login page
     * @param  string|null $password  Plain-text password (only shown on first login email)
     */
    public function sendWelcome(string $email, string $name, string $loginUrl, ?string $password = null): void
    {
        try {
            $smtpSetting = $this->buildSmtpSetting();
            $from        = $this->buildFrom($smtpSetting);

            $data = [
                'name'      => $name,
                'loginUrl'  => $loginUrl,
                'password'  => $password,
                'siteName'  => env('SITE_NAME', 'Dialer'),
                'supportEmail' => env('SUPPORT_EMAIL', env('DEFAULT_EMAIL', 'support@example.com')),
            ];

            $mailable   = new SystemNotificationMail($from, 'emails.welcome', 'Welcome to ' . env('SITE_NAME', 'Dialer'), $data);
            $mailService = new MailService(0, $mailable, $smtpSetting);
            $mailService->sendEmail($email);

            Log::info('WelcomeEmailService: welcome email sent', ['email' => $email]);
        } catch (\Throwable $e) {
            Log::error('WelcomeEmailService: failed to send welcome email', [
                'email' => $email,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Send credential email to a newly created agent.
     *
     * @param  string $agentEmail
     * @param  string $agentName
     * @param  string $username      Login email
     * @param  string $plainPassword Plain-text password
     * @param  string $loginUrl
     * @param  string $companyName
     */
    public function sendAgentWelcome(
        string $agentEmail,
        string $agentName,
        string $username,
        string $plainPassword,
        string $loginUrl,
        string $companyName
    ): void {
        try {
            $smtpSetting = $this->buildSmtpSetting();
            $from        = $this->buildFrom($smtpSetting);

            $data = [
                'agentName'    => $agentName,
                'username'     => $username,
                'password'     => $plainPassword,
                'loginUrl'     => $loginUrl,
                'companyName'  => $companyName,
                'siteName'     => env('SITE_NAME', 'Dialer'),
                'supportEmail' => env('SUPPORT_EMAIL', env('DEFAULT_EMAIL', 'support@example.com')),
            ];

            $subject    = "Your {$companyName} agent account has been created";
            $mailable   = new SystemNotificationMail($from, 'emails.agent-welcome', $subject, $data);
            $mailService = new MailService(0, $mailable, $smtpSetting);
            $mailService->sendEmail($agentEmail);

            Log::info('WelcomeEmailService: agent welcome email sent', ['email' => $agentEmail]);
        } catch (\Throwable $e) {
            Log::error('WelcomeEmailService: failed to send agent welcome email', [
                'email' => $agentEmail,
                'error' => $e->getMessage(),
            ]);
        }
    }

    // ----------------------------------------------------------------
    // Helpers
    // ----------------------------------------------------------------

    private function buildSmtpSetting(): SmtpSetting
    {
        $setting                  = new SmtpSetting();
        $setting->mail_driver     = 'SMTP';
        $setting->mail_host       = env('PORTAL_MAIL_HOST');
        $setting->mail_port       = env('PORTAL_MAIL_PORT');
        $setting->mail_username   = env('PORTAL_MAIL_USERNAME');
        $setting->mail_password   = env('PORTAL_MAIL_PASSWORD');
        $setting->from_name       = env('PORTAL_MAIL_SENDER_NAME');
        $setting->from_email      = env('PORTAL_MAIL_SENDER_EMAIL');
        $setting->mail_encryption = env('PORTAL_MAIL_ENCRYPTION');
        return $setting;
    }

    private function buildFrom(SmtpSetting $setting): array
    {
        return [
            'address' => empty($setting->from_email) ? env('DEFAULT_EMAIL') : $setting->from_email,
            'name'    => empty($setting->from_name)  ? env('DEFAULT_NAME')  : $setting->from_name,
        ];
    }
}
