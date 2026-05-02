<?php

namespace App\Jobs;

use App\Mail\BillingNotificationMail;
use App\Model\Client\SmtpSetting;
use App\Model\Master\Client;
use App\Model\Master\SubscriptionPlan;
use App\Model\User;
use App\Services\MailService;
use Illuminate\Support\Facades\Log;

class PaymentFailedNotificationJob extends Job
{
    public int $tries = 2;

    private int $clientId;
    private ?string $invoiceId;
    private int $amountDue;

    public function __construct(int $clientId, ?string $invoiceId = null, int $amountDue = 0)
    {
        $this->clientId  = $clientId;
        $this->invoiceId = $invoiceId;
        $this->amountDue = $amountDue;
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
                Log::warning('PaymentFailedNotificationJob: no admin email', [
                    'client_id' => $this->clientId,
                ]);
                return;
            }

            $plan = $client->subscription_plan_id
                ? SubscriptionPlan::find($client->subscription_plan_id)
                : null;

            $billingUrl = $this->resolveBillingUrl($client);

            $mailable = new BillingNotificationMail(
                'emails.billing-payment-failed',
                'Action Required: Payment Failed',
                $admin->first_name ?: $admin->email,
                $client->company_name ?: 'Your Account',
                [
                    'plan_name'   => $plan ? $plan->name : 'N/A',
                    'amount_due'  => $this->amountDue,
                    'invoice_id'  => $this->invoiceId,
                    'billing_url' => $billingUrl,
                ]
            );

            $smtp = $this->buildSmtpSetting();
            $mailService = new MailService(0, $mailable, $smtp);
            $mailService->sendEmail($admin->email);

            Log::info('PaymentFailedNotificationJob: email sent', [
                'client_id'  => $this->clientId,
                'email'      => $admin->email,
                'amount_due' => $this->amountDue,
            ]);
        } catch (\Throwable $e) {
            Log::error('PaymentFailedNotificationJob: failed', [
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
