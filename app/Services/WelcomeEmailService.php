<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

/**
 * Sends welcome and onboarding emails after user registration.
 */
class WelcomeEmailService
{
    /**
     * Send a welcome email to a newly registered user.
     */
    public function sendWelcome(
        string  $email,
        string  $name,
        string  $loginUrl,
        ?string $password  = null,
        bool    $hasTrial  = true,
        int     $trialDays = 7
    ): void {
        try {
            SystemMailerService::send('welcome', $email, [
                'name'      => $name,
                'email'     => $email,
                'password'  => $password ?? '',
                'loginUrl'  => $loginUrl,
            ], $name);

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
            SystemMailerService::send('agent-welcome', $agentEmail, [
                'agentName'   => $agentName,
                'username'    => $username,
                'password'    => $plainPassword,
                'loginUrl'    => $loginUrl,
                'companyName' => $companyName,
            ], $agentName);

            Log::info('WelcomeEmailService: agent welcome email sent', ['email' => $agentEmail]);
        } catch (\Throwable $e) {
            Log::error('WelcomeEmailService: failed to send agent welcome email', [
                'email' => $agentEmail,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
