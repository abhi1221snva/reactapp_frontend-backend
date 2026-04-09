<?php

namespace App\Services;

use App\Model\Master\SystemEmailTemplate;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * Unified system email sender.
 *
 * Loads a template from the master DB by key, renders placeholders,
 * and sends via PORTAL_MAIL SMTP credentials from .env.
 *
 * Usage:
 *   SystemMailerService::send('forgot-password', 'user@example.com', [
 *       'firstName' => 'John',
 *       'resetLink' => 'https://...',
 *   ]);
 */
class SystemMailerService
{
    /**
     * Send a system email using a stored template.
     *
     * @param string      $templateKey  One of the system_email_templates.template_key values
     * @param string      $toEmail      Recipient email address
     * @param array       $data         Placeholder key→value pairs
     * @param string|null $toName       Optional recipient name
     * @param string|null $fallbackHtml Raw HTML to use if template not found (graceful degradation)
     * @param string|null $fallbackSubject Subject to use if template not found
     */
    public static function send(
        string  $templateKey,
        string  $toEmail,
        array   $data = [],
        ?string $toName = null,
        ?string $fallbackHtml = null,
        ?string $fallbackSubject = null
    ): void {
        try {
            // Inject common placeholders
            $data = array_merge([
                'siteName'     => env('SITE_NAME', 'LinkSwitch Communications'),
                'companyName'  => env('INVOICE_COMPANY_NAME', env('SITE_NAME', 'LinkSwitch Communications')),
                'supportEmail' => env('DEFAULT_EMAIL', 'support@example.com'),
                'siteUrl'      => env('PORTAL_NAME', ''),
                'currentYear'  => date('Y'),
            ], $data);

            // Load template
            $service  = new EmailTemplateService();
            $template = $service->getByKey($templateKey);

            if ($template && $template->is_active) {
                $subject = TemplateParser::renderSubject($template->subject, $data);
                $html    = TemplateParser::render($template->body_html, $data);
            } elseif ($fallbackHtml) {
                $subject = $fallbackSubject ?? $templateKey;
                $html    = $fallbackHtml;
            } else {
                Log::warning("SystemMailerService: template '{$templateKey}' not found or inactive, email not sent.", [
                    'to' => $toEmail,
                ]);
                return;
            }

            // Build SMTP transport from PORTAL_MAIL env vars
            $smtpHost   = env('PORTAL_MAIL_HOST', 'smtp.sendgrid.net');
            $smtpPort   = (int) env('PORTAL_MAIL_PORT', 587);
            $smtpUser   = env('PORTAL_MAIL_USERNAME', 'apikey');
            $smtpPass   = env('PORTAL_MAIL_PASSWORD', '');
            $encryption = strtolower(env('PORTAL_MAIL_ENCRYPTION', 'tls'));
            $fromEmail  = env('PORTAL_MAIL_SENDER_EMAIL', env('DEFAULT_EMAIL', 'noreply@example.com'));
            $fromName   = env('PORTAL_MAIL_SENDER_NAME', env('DEFAULT_NAME', 'System'));

            $dsn = sprintf(
                'smtp://%s:%s@%s:%d?encryption=%s',
                urlencode($smtpUser),
                urlencode($smtpPass),
                $smtpHost,
                $smtpPort,
                $encryption
            );

            $transport = Transport::fromDsn($dsn);
            $mailer    = new Mailer($transport);

            $email = (new Email())
                ->from(new Address($fromEmail, $fromName))
                ->to($toName ? new Address($toEmail, $toName) : $toEmail)
                ->subject($subject)
                ->html($html);

            $mailer->send($email);

            Log::info("SystemMailerService: sent '{$templateKey}' to {$toEmail}");

        } catch (\Throwable $e) {
            Log::error("SystemMailerService: failed to send '{$templateKey}'", [
                'to'      => $toEmail,
                'error'   => $e->getMessage(),
                'file'    => $e->getFile(),
                'line'    => $e->getLine(),
            ]);
            throw $e;
        }
    }
}
