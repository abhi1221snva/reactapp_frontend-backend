<?php

namespace App\Jobs;

use App\Mail\BillingNotificationMail;
use App\Model\Client\SmtpSetting;
use App\Model\Master\Client;
use App\Model\Master\SubscriptionPlan;
use App\Model\User;
use App\Services\MailService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class TrialEndingNotificationJob extends Job
{
    public int $tries = 2;

    private int $clientId;

    public function __construct(int $clientId)
    {
        $this->clientId = $clientId;
    }

    public function handle(): void
    {
        try {
            $client = Client::find($this->clientId);
            if (!$client || $client->subscription_status !== 'trial') {
                return; // already upgraded or expired
            }

            $admin = User::where('parent_id', $this->clientId)
                ->whereIn('role', [6, 7]) // client admin roles
                ->where('is_deleted', 0)
                ->where('status', 1)
                ->first();

            if (!$admin || !$admin->email) {
                Log::warning('TrialEndingNotificationJob: no admin email', [
                    'client_id' => $this->clientId,
                ]);
                return;
            }

            $expiresAt = Carbon::parse($client->subscription_ends_at);
            $daysRemaining = max(0, (int) Carbon::now()->diffInDays($expiresAt, false));

            $plan = $client->subscription_plan_id
                ? SubscriptionPlan::find($client->subscription_plan_id)
                : null;

            $billingUrl = $this->resolveBillingUrl($client);

            $mailable = new BillingNotificationMail(
                'emails.billing-trial-ending',
                "Your trial expires in {$daysRemaining} " . ($daysRemaining == 1 ? 'day' : 'days'),
                $admin->first_name ?: $admin->email,
                $client->company_name ?: 'Your Account',
                [
                    'days_remaining' => $daysRemaining,
                    'plan_name'      => $plan ? $plan->name : 'Starter',
                    'expires_at'     => $expiresAt->format('M j, Y \a\t g:i A'),
                    'wallet_balance' => $this->getWalletBalance(),
                    'billing_url'    => $billingUrl,
                ]
            );

            $smtp = $this->buildSmtpSetting();
            $mailService = new MailService(0, $mailable, $smtp);
            $mailService->sendEmail($admin->email);

            Log::info('TrialEndingNotificationJob: email sent', [
                'client_id'      => $this->clientId,
                'email'          => $admin->email,
                'days_remaining' => $daysRemaining,
            ]);
        } catch (\Throwable $e) {
            Log::error('TrialEndingNotificationJob: failed', [
                'client_id' => $this->clientId,
                'error'     => $e->getMessage(),
            ]);
        }
    }

    private function getWalletBalance(): float
    {
        try {
            $row = \DB::connection("mysql_{$this->clientId}")
                ->table('wallet')
                ->where('currency_code', 'USD')
                ->first();
            return $row ? (float) $row->amount : 0;
        } catch (\Throwable $e) {
            return 0;
        }
    }

    private function resolveBillingUrl(Client $client): string
    {
        $baseUrl = $client->website_url
            ?: env('APP_FRONTEND_URL', env('APP_URL', 'https://app.rocketdialer.com'));
        return rtrim($baseUrl, '/') . '/billing';
    }

    private function buildSmtpSetting(): SmtpSetting
    {
        $smtp = new SmtpSetting();
        $smtp->mail_driver     = 'SMTP';
        $smtp->mail_host       = env('PORTAL_MAIL_HOST');
        $smtp->mail_port       = env('PORTAL_MAIL_PORT');
        $smtp->mail_username   = env('PORTAL_MAIL_USERNAME');
        $smtp->mail_password   = env('PORTAL_MAIL_PASSWORD');
        $smtp->from_name       = env('PORTAL_MAIL_SENDER_NAME', 'Rocket Dialer');
        $smtp->from_email      = env('PORTAL_MAIL_SENDER_EMAIL', env('DEFAULT_EMAIL'));
        $smtp->mail_encryption = env('PORTAL_MAIL_ENCRYPTION', 'tls');
        return $smtp;
    }
}
