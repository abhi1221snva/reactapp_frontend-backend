<?php

namespace App\Jobs;

use App\Mail\BillingNotificationMail;
use App\Model\Client\SmtpSetting;
use App\Model\Master\Client;
use App\Model\User;
use App\Services\MailService;
use Illuminate\Support\Facades\Log;

class WalletLowBalanceNotificationJob extends Job
{
    public int $tries = 2;

    private int $clientId;
    private float $balance;
    private float $threshold;

    public function __construct(int $clientId, float $balance, float $threshold)
    {
        $this->clientId  = $clientId;
        $this->balance   = $balance;
        $this->threshold = $threshold;
    }

    public function handle(): void
    {
        try {
            $client = Client::find($this->clientId);
            if (!$client) {
                return;
            }

            $admin = User::where('parent_id', $this->clientId)
                ->whereIn('role', [6, 7])
                ->where('is_deleted', 0)
                ->where('status', 1)
                ->first();

            if (!$admin || !$admin->email) {
                Log::warning('WalletLowBalanceNotificationJob: no admin email', [
                    'client_id' => $this->clientId,
                ]);
                return;
            }

            $billingUrl = $this->resolveBillingUrl($client);

            $mailable = new BillingNotificationMail(
                'emails.billing-low-balance',
                'Low Wallet Balance: $' . number_format($this->balance, 2),
                $admin->first_name ?: $admin->email,
                $client->company_name ?: 'Your Account',
                [
                    'balance'     => $this->balance,
                    'threshold'   => $this->threshold,
                    'billing_url' => $billingUrl,
                ]
            );

            $smtp = $this->buildSmtpSetting();
            $mailService = new MailService(0, $mailable, $smtp);
            $mailService->sendEmail($admin->email);

            Log::info('WalletLowBalanceNotificationJob: email sent', [
                'client_id' => $this->clientId,
                'email'     => $admin->email,
                'balance'   => $this->balance,
                'threshold' => $this->threshold,
            ]);
        } catch (\Throwable $e) {
            Log::error('WalletLowBalanceNotificationJob: failed', [
                'client_id' => $this->clientId,
                'error'     => $e->getMessage(),
            ]);
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
