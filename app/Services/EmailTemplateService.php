<?php

namespace App\Services;

use App\Model\Master\SystemEmailTemplate;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class EmailTemplateService
{
    private const CACHE_PREFIX = 'sys_email_tpl:';
    private const CACHE_TTL    = 3600; // 1 hour

    public function getAll()
    {
        return SystemEmailTemplate::orderBy('id')->get();
    }

    public function getByKey(string $key): ?SystemEmailTemplate
    {
        return Cache::remember(self::CACHE_PREFIX . $key, self::CACHE_TTL, function () use ($key) {
            return SystemEmailTemplate::findByKey($key);
        });
    }

    public function update(int $id, array $data): SystemEmailTemplate
    {
        $template = SystemEmailTemplate::findOrFail($id);
        $template->fill($data);
        $template->save();

        Cache::forget(self::CACHE_PREFIX . $template->template_key);

        return $template->fresh();
    }

    public function preview(string $key, array $sampleData = []): array
    {
        $template = $this->getByKey($key);
        if (!$template) {
            return ['subject' => '', 'html' => '<p>Template not found.</p>'];
        }

        $data = array_merge($this->getSampleData($key), $sampleData);

        return [
            'subject' => TemplateParser::renderSubject($template->subject, $data),
            'html'    => TemplateParser::render($template->body_html, $data),
        ];
    }

    /**
     * Seed default templates. Only inserts if key does not exist yet.
     */
    public function seedDefaults(): int
    {
        $defaults = $this->getDefaultTemplates();
        $inserted = 0;

        foreach ($defaults as $tpl) {
            $exists = SystemEmailTemplate::where('template_key', $tpl['template_key'])->exists();
            if (!$exists) {
                SystemEmailTemplate::create($tpl);
                $inserted++;
            }
        }

        return $inserted;
    }

    public function getSampleData(string $key): array
    {
        $common = [
            'siteName'    => env('SITE_NAME', 'LinkSwitch Communications'),
            'siteUrl'     => env('PORTAL_NAME', 'https://example.com'),
            'companyName' => env('INVOICE_COMPANY_NAME', env('SITE_NAME', 'LinkSwitch Communications')),
            'supportEmail' => env('DEFAULT_EMAIL', 'support@example.com'),
            'currentYear' => date('Y'),
        ];

        $specific = [
            'forgot-password' => [
                'firstName' => 'John',
                'lastName'  => 'Doe',
                'resetLink' => 'https://example.com/reset-password?token=sample123&email=john@example.com',
            ],
            'welcome' => [
                'name'      => 'John Doe',
                'email'     => 'john@example.com',
                'password'  => 'TempPass123!',
                'loginUrl'  => 'https://example.com/login',
            ],
            'agent-welcome' => [
                'agentName'   => 'Jane Agent',
                'username'    => 'jane@example.com',
                'password'    => 'AgentPass456!',
                'loginUrl'    => 'https://example.com/login',
                'companyName' => 'Acme Corp',
            ],
            'email-verification' => [
                'name'    => 'John',
                'otpCode' => '482917',
            ],
            'login-otp' => [
                'userName' => 'John Doe',
                'otpCode'  => '739215',
            ],
            'welcome-google' => [
                'name'     => 'John Doe',
                'email'    => 'john@gmail.com',
                'loginUrl' => 'https://example.com/login',
            ],
            'error-notification' => [
                'errorMessage' => 'SMTP connection timed out after 30s',
                'errorContext' => 'SendCrmNotificationEmail job, client_id=42',
            ],
            'generic-notification' => [
                'title'   => 'System Notification',
                'message' => 'This is a sample notification message.',
            ],
        ];

        return array_merge($common, $specific[$key] ?? []);
    }

    // ------------------------------------------------------------------
    // Default template definitions
    // ------------------------------------------------------------------

    private function getDefaultTemplates(): array
    {
        $siteName    = env('SITE_NAME', 'LinkSwitch Communications');
        $companyName = env('INVOICE_COMPANY_NAME', $siteName);

        return [
            // 1. Forgot Password
            [
                'template_key'  => 'forgot-password',
                'template_name' => 'Forgot Password',
                'subject'       => 'Reset Your Password — {{siteName}}',
                'body_html'     => $this->forgotPasswordHtml(),
                'placeholders'  => json_encode([
                    ['key' => 'firstName',  'label' => 'First Name',  'sample' => 'John'],
                    ['key' => 'lastName',   'label' => 'Last Name',   'sample' => 'Doe'],
                    ['key' => 'resetLink',  'label' => 'Reset Link',  'sample' => 'https://example.com/reset'],
                    ['key' => 'siteName',   'label' => 'Site Name',   'sample' => $siteName],
                    ['key' => 'companyName','label' => 'Company Name','sample' => $companyName],
                ]),
                'is_active' => 1,
            ],

            // 2. Welcome
            [
                'template_key'  => 'welcome',
                'template_name' => 'Welcome Email',
                'subject'       => 'Welcome to {{siteName}}!',
                'body_html'     => $this->welcomeHtml(),
                'placeholders'  => json_encode([
                    ['key' => 'name',      'label' => 'Full Name',        'sample' => 'John Doe'],
                    ['key' => 'email',     'label' => 'Email',            'sample' => 'john@example.com'],
                    ['key' => 'password',  'label' => 'Temporary Password','sample' => 'TempPass123!'],
                    ['key' => 'loginUrl',  'label' => 'Login URL',        'sample' => 'https://example.com/login'],
                    ['key' => 'siteName',  'label' => 'Site Name',        'sample' => $siteName],
                    ['key' => 'companyName','label' => 'Company Name',    'sample' => $companyName],
                    ['key' => 'supportEmail','label' => 'Support Email',  'sample' => 'support@example.com'],
                ]),
                'is_active' => 1,
            ],

            // 3. Agent Welcome
            [
                'template_key'  => 'agent-welcome',
                'template_name' => 'Agent Welcome',
                'subject'       => 'Your Agent Account — {{companyName}}',
                'body_html'     => $this->agentWelcomeHtml(),
                'placeholders'  => json_encode([
                    ['key' => 'agentName',  'label' => 'Agent Name',       'sample' => 'Jane Agent'],
                    ['key' => 'username',   'label' => 'Username / Email', 'sample' => 'jane@example.com'],
                    ['key' => 'password',   'label' => 'Password',         'sample' => 'AgentPass456!'],
                    ['key' => 'loginUrl',   'label' => 'Login URL',        'sample' => 'https://example.com/login'],
                    ['key' => 'companyName','label' => 'Company Name',     'sample' => 'Acme Corp'],
                    ['key' => 'siteName',   'label' => 'Site Name',        'sample' => $siteName],
                    ['key' => 'supportEmail','label' => 'Support Email',   'sample' => 'support@example.com'],
                ]),
                'is_active' => 1,
            ],

            // 4. Email Verification
            [
                'template_key'  => 'email-verification',
                'template_name' => 'Email Verification OTP',
                'subject'       => 'Verify Your Email — {{siteName}}',
                'body_html'     => $this->emailVerificationHtml(),
                'placeholders'  => json_encode([
                    ['key' => 'name',     'label' => 'Name',      'sample' => 'John'],
                    ['key' => 'otpCode',  'label' => 'OTP Code',  'sample' => '482917'],
                    ['key' => 'siteName', 'label' => 'Site Name', 'sample' => $siteName],
                    ['key' => 'companyName','label' => 'Company Name','sample' => $companyName],
                    ['key' => 'supportEmail','label' => 'Support Email','sample' => 'support@example.com'],
                ]),
                'is_active' => 1,
            ],

            // 5. Login OTP
            [
                'template_key'  => 'login-otp',
                'template_name' => 'Login OTP',
                'subject'       => 'Your Login Code — {{siteName}}',
                'body_html'     => $this->loginOtpHtml(),
                'placeholders'  => json_encode([
                    ['key' => 'userName', 'label' => 'User Name', 'sample' => 'John Doe'],
                    ['key' => 'otpCode',  'label' => 'OTP Code',  'sample' => '739215'],
                    ['key' => 'siteName', 'label' => 'Site Name', 'sample' => $siteName],
                    ['key' => 'companyName','label' => 'Company Name','sample' => $companyName],
                ]),
                'is_active' => 1,
            ],

            // 6. Welcome Google
            [
                'template_key'  => 'welcome-google',
                'template_name' => 'Welcome (Google Login)',
                'subject'       => 'Welcome to {{siteName}}!',
                'body_html'     => $this->welcomeGoogleHtml(),
                'placeholders'  => json_encode([
                    ['key' => 'name',     'label' => 'Name',      'sample' => 'John Doe'],
                    ['key' => 'email',    'label' => 'Email',     'sample' => 'john@gmail.com'],
                    ['key' => 'loginUrl', 'label' => 'Login URL', 'sample' => 'https://example.com/login'],
                    ['key' => 'siteName', 'label' => 'Site Name', 'sample' => $siteName],
                    ['key' => 'companyName','label' => 'Company Name','sample' => $companyName],
                ]),
                'is_active' => 1,
            ],

            // 7. Error Notification
            [
                'template_key'  => 'error-notification',
                'template_name' => 'Error Notification',
                'subject'       => 'System Error — {{siteName}}',
                'body_html'     => $this->errorNotificationHtml(),
                'placeholders'  => json_encode([
                    ['key' => 'errorMessage','label' => 'Error Message','sample' => 'SMTP connection failed'],
                    ['key' => 'errorContext','label' => 'Error Context','sample' => 'Job: SendEmail, client=42'],
                    ['key' => 'siteName',    'label' => 'Site Name',   'sample' => $siteName],
                    ['key' => 'companyName', 'label' => 'Company Name','sample' => $companyName],
                ]),
                'is_active' => 1,
            ],

            // 8. Generic Notification
            [
                'template_key'  => 'generic-notification',
                'template_name' => 'Generic Notification',
                'subject'       => '{{title}} — {{siteName}}',
                'body_html'     => $this->genericNotificationHtml(),
                'placeholders'  => json_encode([
                    ['key' => 'title',       'label' => 'Title',        'sample' => 'System Notification'],
                    ['key' => 'message',     'label' => 'Message',      'sample' => 'This is a notification.'],
                    ['key' => 'siteName',    'label' => 'Site Name',    'sample' => $siteName],
                    ['key' => 'companyName', 'label' => 'Company Name', 'sample' => $companyName],
                ]),
                'is_active' => 1,
            ],
        ];
    }

    // ------------------------------------------------------------------
    // HTML template bodies (pure HTML with {{placeholder}} tokens)
    // ------------------------------------------------------------------

    private function emailShell(string $headerBg, string $headerContent, string $bodyContent): string
    {
        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>
<body style="margin:0;padding:0;background-color:#f4f6f9;font-family:Arial,Helvetica,sans-serif;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f4f6f9;padding:40px 0;">
<tr><td align="center">
<table role="presentation" width="520" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:12px;box-shadow:0 2px 8px rgba(0,0,0,0.06);overflow:hidden;">
<tr><td style="background:{$headerBg};padding:28px 36px;text-align:center;">{$headerContent}</td></tr>
<tr><td style="padding:36px 36px 28px;">{$bodyContent}</td></tr>
<tr><td style="background:#f8fafc;padding:20px 36px;text-align:center;border-top:1px solid #e2e8f0;">
<p style="margin:0;font-size:12px;color:#94a3b8;">{{companyName}}</p>
<p style="margin:4px 0 0;font-size:11px;color:#cbd5e1;">This is an automated message — please do not reply.</p>
</td></tr>
</table>
</td></tr>
</table>
</body>
</html>
HTML;
    }

    private function headerTitle(string $title): string
    {
        return '<span style="color:#ffffff;font-size:22px;font-weight:700;letter-spacing:0.5px;">{{siteName}}</span>'
             . '<p style="margin:8px 0 0;color:#e0e7ff;font-size:14px;">' . $title . '</p>';
    }

    private function forgotPasswordHtml(): string
    {
        $header = '<span style="color:#ffffff;font-size:22px;font-weight:700;letter-spacing:0.5px;">{{siteName}}</span>';
        $body = <<<'HTML'
<h2 style="margin:0 0 6px;font-size:20px;font-weight:700;color:#1e293b;">Password Reset Request</h2>
<p style="margin:0 0 24px;font-size:14px;color:#94a3b8;">We received a request to reset your password.</p>
<p style="margin:0 0 24px;font-size:14px;line-height:1.6;color:#475569;">
    Hi <strong>{{firstName}} {{lastName}}</strong>,<br><br>
    Click the button below to set a new password. This link will expire in <strong>30 minutes</strong>.
</p>
<table role="presentation" cellpadding="0" cellspacing="0" style="margin:0 auto 28px;">
<tr><td style="border-radius:8px;background:#4f46e5;">
<a href="{{resetLink}}" target="_blank" style="display:inline-block;padding:14px 40px;font-size:15px;font-weight:600;color:#ffffff;text-decoration:none;border-radius:8px;">Reset My Password</a>
</td></tr>
</table>
<p style="margin:0 0 20px;font-size:13px;line-height:1.5;color:#94a3b8;">If the button doesn't work, copy and paste this link into your browser:</p>
<p style="margin:0 0 28px;font-size:12px;word-break:break-all;color:#6366f1;">{{resetLink}}</p>
<hr style="border:none;border-top:1px solid #e2e8f0;margin:0 0 20px;" />
<p style="margin:0;font-size:12px;line-height:1.5;color:#94a3b8;">If you didn't request a password reset, you can safely ignore this email. Your password will remain unchanged.</p>
HTML;
        return $this->emailShell('linear-gradient(135deg,#4f46e5,#6366f1)', $header, $body);
    }

    private function welcomeHtml(): string
    {
        $header = $this->headerTitle('Your account is ready — let\'s get started');
        $body = <<<'HTML'
<p style="margin:0 0 16px;font-size:15px;line-height:1.7;color:#374151;">Hi <strong>{{name}}</strong>,</p>
<p style="margin:0 0 16px;font-size:15px;line-height:1.7;color:#374151;">
    Congratulations! Your <strong>{{siteName}}</strong> account has been successfully created.
    You now have access to a powerful dialer and CRM platform designed to help your business grow.
</p>
<div style="background:#f9fafb;border:1px solid #e5e7eb;border-radius:6px;padding:16px 20px;margin:16px 0;">
    <p style="margin:0 0 4px;font-size:14px;color:#374151;"><strong>Your login credentials:</strong></p>
    <p style="margin:4px 0;font-size:14px;color:#374151;">Username / Email: <strong>{{email}}</strong></p>
    <p style="margin:4px 0;font-size:14px;color:#374151;">Temporary Password: <strong>{{password}}</strong></p>
    <p style="font-size:12px;color:#6b7280;margin:8px 0 0;">Please change your password after your first login.</p>
</div>
<p style="margin:16px 0 8px;font-size:15px;font-weight:600;color:#374151;">Getting started is easy:</p>
<ol style="margin:0 0 20px;padding-left:24px;color:#374151;font-size:14px;line-height:2;">
    <li>Log in to your account</li>
    <li>Complete your profile and verify your phone number</li>
    <li>Create your first agent</li>
    <li>Configure your lead fields</li>
    <li>Launch your first campaign</li>
</ol>
<table role="presentation" cellpadding="0" cellspacing="0" style="margin:0 auto 20px;">
<tr><td style="border-radius:8px;background:#4f46e5;">
<a href="{{loginUrl}}" target="_blank" style="display:inline-block;padding:13px 28px;font-size:15px;font-weight:600;color:#ffffff;text-decoration:none;border-radius:8px;">Log In Now</a>
</td></tr>
</table>
<p style="margin:0;font-size:14px;line-height:1.7;color:#374151;">
    If you have any questions, contact us at <a href="mailto:{{supportEmail}}" style="color:#4f46e5;">{{supportEmail}}</a>.
</p>
HTML;
        return $this->emailShell('linear-gradient(135deg,#4f46e5 0%,#7c3aed 100%)', $header, $body);
    }

    private function agentWelcomeHtml(): string
    {
        $header = '<span style="color:#ffffff;font-size:22px;font-weight:700;">Your Agent Account is Ready</span>'
                . '<p style="margin:8px 0 0;color:#94a3b8;font-size:14px;">{{companyName}} — Agent Portal</p>';
        $body = <<<'HTML'
<p style="margin:0 0 14px;font-size:15px;line-height:1.7;color:#374151;">Hello <strong>{{agentName}}</strong>,</p>
<p style="margin:0 0 14px;font-size:15px;line-height:1.7;color:#374151;">
    An agent account has been created for you on the <strong>{{companyName}}</strong> platform.
    You can log in using the credentials below.
</p>
<div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;padding:20px 24px;margin:20px 0;">
    <p style="margin:0 0 12px;font-weight:600;color:#1e293b;">Your Login Credentials</p>
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
    <tr>
        <td style="padding:6px 0;border-bottom:1px solid #dbeafe;font-size:13px;color:#6b7280;font-weight:600;text-transform:uppercase;letter-spacing:.4px;">Email / Username</td>
        <td style="padding:6px 0;border-bottom:1px solid #dbeafe;font-size:14px;color:#1e293b;font-weight:600;font-family:'Courier New',monospace;text-align:right;">{{username}}</td>
    </tr>
    <tr>
        <td style="padding:6px 0;font-size:13px;color:#6b7280;font-weight:600;text-transform:uppercase;letter-spacing:.4px;">Password</td>
        <td style="padding:6px 0;font-size:14px;color:#1e293b;font-weight:600;font-family:'Courier New',monospace;text-align:right;">{{password}}</td>
    </tr>
    </table>
</div>
<div style="background:#fef3c7;border-left:4px solid #f59e0b;padding:12px 16px;border-radius:0 6px 6px 0;font-size:13px;color:#92400e;margin:16px 0;">
    <strong>Security Notice:</strong> Please change your password immediately after your first login. Do not share these credentials with anyone.
</div>
<table role="presentation" cellpadding="0" cellspacing="0" style="margin:0 auto 16px;">
<tr><td style="border-radius:8px;background:#4f46e5;">
<a href="{{loginUrl}}" target="_blank" style="display:inline-block;padding:13px 28px;font-size:15px;font-weight:600;color:#ffffff;text-decoration:none;border-radius:8px;">Log In to Your Account</a>
</td></tr>
</table>
<p style="margin:0;font-size:14px;line-height:1.7;color:#374151;">
    If you were not expecting this email, please contact your administrator or reach out to us at
    <a href="mailto:{{supportEmail}}" style="color:#4f46e5;">{{supportEmail}}</a>.
</p>
HTML;
        return $this->emailShell('#1e293b', $header, $body);
    }

    private function emailVerificationHtml(): string
    {
        $header = '<span style="color:#ffffff;font-size:22px;font-weight:700;letter-spacing:0.3px;">{{siteName}}</span>'
                . '<p style="margin:8px 0 0;color:#e0e7ff;font-size:14px;">Verify Your Email Address</p>';
        $body = <<<'HTML'
<p style="margin:0 0 16px;font-size:15px;line-height:1.7;color:#374151;">Hello {{name}},</p>
<p style="margin:0 0 16px;font-size:15px;line-height:1.7;color:#374151;">
    Thank you for signing up for <strong>{{siteName}}</strong>!
    To complete your registration, please verify your email address using the one-time code below:
</p>
<div style="background:#f0f0ff;border:2px dashed #4f46e5;border-radius:8px;text-align:center;padding:20px 16px;margin:24px 0;">
    <div style="font-size:38px;font-weight:700;letter-spacing:12px;color:#4f46e5;font-family:'Courier New',monospace;">{{otpCode}}</div>
    <div style="font-size:13px;color:#6b7280;margin-top:8px;">This code is valid for <strong>15 minutes</strong></div>
</div>
<p style="margin:0 0 16px;font-size:14px;line-height:1.7;color:#374151;">
    If you did not create an account, please ignore this email — no action is required.
</p>
<p style="margin:0;font-size:14px;line-height:1.7;color:#374151;">
    For support, contact us at <a href="mailto:{{supportEmail}}" style="color:#4f46e5;">{{supportEmail}}</a>.
</p>
HTML;
        return $this->emailShell('#4f46e5', $header, $body);
    }

    private function loginOtpHtml(): string
    {
        $header = '<span style="color:#ffffff;font-size:22px;font-weight:700;letter-spacing:0.5px;">{{siteName}}</span>';
        $body = <<<'HTML'
<h2 style="margin:0 0 6px;font-size:20px;font-weight:700;color:#1e293b;">Login Verification</h2>
<p style="margin:0 0 24px;font-size:14px;color:#94a3b8;">A login attempt requires verification.</p>
<p style="margin:0 0 16px;font-size:15px;line-height:1.7;color:#374151;">Hi <strong>{{userName}}</strong>,</p>
<p style="margin:0 0 16px;font-size:15px;line-height:1.7;color:#374151;">
    Use the following one-time code to complete your login:
</p>
<div style="background:#f0f0ff;border:2px dashed #4f46e5;border-radius:8px;text-align:center;padding:20px 16px;margin:24px 0;">
    <div style="font-size:38px;font-weight:700;letter-spacing:12px;color:#4f46e5;font-family:'Courier New',monospace;">{{otpCode}}</div>
    <div style="font-size:13px;color:#6b7280;margin-top:8px;">This code expires in <strong>5 minutes</strong></div>
</div>
<hr style="border:none;border-top:1px solid #e2e8f0;margin:0 0 20px;" />
<p style="margin:0;font-size:12px;line-height:1.5;color:#94a3b8;">
    If you did not attempt to log in, please secure your account immediately by changing your password.
</p>
HTML;
        return $this->emailShell('linear-gradient(135deg,#4f46e5,#6366f1)', $header, $body);
    }

    private function welcomeGoogleHtml(): string
    {
        $header = $this->headerTitle('Signed in with Google');
        $body = <<<'HTML'
<p style="margin:0 0 16px;font-size:15px;line-height:1.7;color:#374151;">Hi <strong>{{name}}</strong>,</p>
<p style="margin:0 0 16px;font-size:15px;line-height:1.7;color:#374151;">
    Welcome to <strong>{{siteName}}</strong>! Your account has been created using your Google account (<strong>{{email}}</strong>).
</p>
<p style="margin:0 0 16px;font-size:15px;line-height:1.7;color:#374151;">
    You can log in anytime using the "Sign in with Google" button on our login page.
</p>
<table role="presentation" cellpadding="0" cellspacing="0" style="margin:0 auto 20px;">
<tr><td style="border-radius:8px;background:#4f46e5;">
<a href="{{loginUrl}}" target="_blank" style="display:inline-block;padding:13px 28px;font-size:15px;font-weight:600;color:#ffffff;text-decoration:none;border-radius:8px;">Go to Dashboard</a>
</td></tr>
</table>
<p style="margin:0;font-size:14px;line-height:1.7;color:#374151;">
    If you did not create this account, please contact us at <a href="mailto:{{supportEmail}}" style="color:#4f46e5;">{{supportEmail}}</a>.
</p>
HTML;
        return $this->emailShell('linear-gradient(135deg,#4f46e5 0%,#7c3aed 100%)', $header, $body);
    }

    private function errorNotificationHtml(): string
    {
        $header = '<span style="color:#ffffff;font-size:22px;font-weight:700;">System Error Alert</span>';
        $body = <<<'HTML'
<h2 style="margin:0 0 6px;font-size:20px;font-weight:700;color:#dc2626;">Error Detected</h2>
<p style="margin:0 0 24px;font-size:14px;color:#94a3b8;">An error occurred that requires attention.</p>
<div style="background:#fef2f2;border:1px solid #fecaca;border-radius:8px;padding:16px 20px;margin:0 0 20px;">
    <p style="margin:0 0 8px;font-size:13px;font-weight:600;color:#991b1b;text-transform:uppercase;letter-spacing:.4px;">Error Message</p>
    <p style="margin:0;font-size:14px;color:#dc2626;font-family:'Courier New',monospace;">{{errorMessage}}</p>
</div>
<div style="background:#f9fafb;border:1px solid #e5e7eb;border-radius:8px;padding:16px 20px;margin:0 0 20px;">
    <p style="margin:0 0 8px;font-size:13px;font-weight:600;color:#374151;text-transform:uppercase;letter-spacing:.4px;">Context</p>
    <p style="margin:0;font-size:14px;color:#475569;">{{errorContext}}</p>
</div>
<p style="margin:0;font-size:12px;line-height:1.5;color:#94a3b8;">Please investigate and resolve this issue promptly.</p>
HTML;
        return $this->emailShell('#dc2626', $header, $body);
    }

    private function genericNotificationHtml(): string
    {
        $header = '<span style="color:#ffffff;font-size:22px;font-weight:700;letter-spacing:0.5px;">{{siteName}}</span>';
        $body = <<<'HTML'
<h2 style="margin:0 0 16px;font-size:20px;font-weight:700;color:#1e293b;">{{title}}</h2>
<p style="margin:0 0 20px;font-size:15px;line-height:1.7;color:#374151;">{{message}}</p>
<hr style="border:none;border-top:1px solid #e2e8f0;margin:0 0 20px;" />
<p style="margin:0;font-size:12px;line-height:1.5;color:#94a3b8;">This notification was sent by {{siteName}}.</p>
HTML;
        return $this->emailShell('linear-gradient(135deg,#4f46e5,#6366f1)', $header, $body);
    }
}
