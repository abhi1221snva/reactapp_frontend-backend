<?php

namespace App\Services;

use App\Model\Client\EmailSetting;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Address;

/**
 * Unified dynamic-SMTP email sender.
 *
 * Usage:
 *   EmailService::forSetting($setting)->send($to, $subject, $html);
 *   EmailService::forClient($clientId, 'notification')->send($to, $subject, $html);
 *   EmailService::test($config, $to);          // pre-save test
 */
class EmailService
{
    private Mailer  $mailer;
    private Address $from;

    private function __construct(Mailer $mailer, Address $from)
    {
        $this->mailer = $mailer;
        $this->from   = $from;
    }

    // ── Factory: from an EmailSetting model ────────────────────────────────────
    public static function forSetting(EmailSetting $setting): self
    {
        return self::buildFromParams(
            host:       $setting->mail_host,
            port:       (int) $setting->mail_port,
            username:   $setting->mail_username,
            password:   $setting->plainPassword(),
            encryption: $setting->mail_encryption,
            fromEmail:  $setting->sender_email ?: env('DEFAULT_EMAIL', 'noreply@example.com'),
            fromName:   $setting->sender_name  ?: env('DEFAULT_NAME',  'Rocket Dialer'),
        );
    }

    // ── Factory: auto-resolve active setting for a client + mail type ──────────
    public static function forClient(int $clientId, string $mailType = 'notification'): self
    {
        $setting = EmailSetting::on("mysql_{$clientId}")
            ->where('mail_type', $mailType)
            ->where('status', 1)
            ->first();

        if (!$setting) {
            throw new \RuntimeException("No active email config found for type '{$mailType}' (client {$clientId}).");
        }

        return self::forSetting($setting);
    }

    // ── Factory: any active SMTP for client (fallback when type is unknown) ────
    public static function forClientAny(int $clientId): self
    {
        $setting = EmailSetting::on("mysql_{$clientId}")
            ->where('status', 1)
            ->orderByRaw("FIELD(mail_type,'submission','notification','general') DESC")
            ->first();

        if (!$setting) {
            throw new \RuntimeException("No active email config found for client {$clientId}.");
        }

        return self::forSetting($setting);
    }

    // ── Factory: system default from .env MAIL_* settings ─────────────────────
    public static function systemDefault(): self
    {
        $host = env('MAIL_HOST');
        if (!$host) {
            throw new \RuntimeException('No system default MAIL_HOST configured.');
        }

        return self::buildFromParams(
            host:       $host,
            port:       (int) env('MAIL_PORT', 587),
            username:   env('MAIL_USERNAME', ''),
            password:   env('MAIL_PASSWORD', ''),
            encryption: env('MAIL_ENCRYPTION', 'tls'),
            fromEmail:  env('MAIL_FROM_ADDRESS', env('DEFAULT_EMAIL', 'noreply@example.com')),
            fromName:   env('MAIL_FROM_NAME',    env('DEFAULT_NAME',  'Rocket Dialer')),
        );
    }

    // ── Factory: from raw array (for test-before-save) ─────────────────────────
    public static function fromRaw(array $config): self
    {
        return self::buildFromParams(
            host:       $config['mail_host'],
            port:       (int) ($config['mail_port'] ?? 587),
            username:   $config['mail_username'],
            password:   $config['mail_password'],
            encryption: $config['mail_encryption'] ?? 'tls',
            fromEmail:  $config['sender_email'] ?? env('DEFAULT_EMAIL', 'noreply@example.com'),
            fromName:   $config['sender_name']  ?? env('DEFAULT_NAME',  'Rocket Dialer'),
        );
    }

    // ── Core send ──────────────────────────────────────────────────────────────
    /**
     * @param  string|string[]  $to
     */
    public function send(
        string|array $to,
        string $subject,
        string $html,
        array  $cc          = [],
        array  $bcc         = [],
        array  $attachments = [],
    ): void {
        $email = (new Email())
            ->from($this->from)
            ->subject($subject)
            ->html($html);

        foreach ((array) $to as $addr) {
            $email->addTo($addr);
        }
        foreach ($cc  as $addr) { $email->addCc($addr);  }
        foreach ($bcc as $addr) { $email->addBcc($addr); }

        foreach ($attachments as $path) {
            if (is_file($path)) {
                $email->attachFromPath($path);
            } else {
                Log::warning("EmailService: attachment not found or not a file: {$path}");
            }
        }

        $this->mailer->send($email);
    }

    // ── Convenience: test with raw config array ────────────────────────────────
    public static function test(array $config, string $testTo): array
    {
        try {
            $service = self::fromRaw($config);
            $service->send(
                to:      $testTo,
                subject: 'Test Email — Rocket Dialer',
                html:    '<p>This is a test email from <strong>Rocket Dialer CRM</strong>. If you received this, your SMTP configuration is working correctly.</p>',
            );
            return ['success' => true, 'message' => "Test email sent successfully to {$testTo}"];
        } catch (\Throwable $e) {
            Log::error('EmailService::test failed', ['error' => $e->getMessage()]);
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    // ── Internal builder ───────────────────────────────────────────────────────
    private static function buildFromParams(
        string  $host,
        int     $port,
        string  $username,
        ?string $password,
        string  $encryption,
        string  $fromEmail,
        string  $fromName,
    ): self {
        $enc = strtolower(trim($encryption));
        $dsn = sprintf(
            'smtp://%s:%s@%s:%d?encryption=%s&verify_peer=0',
            urlencode($username),
            urlencode($password ?? ''),
            $host,
            $port,
            $enc === 'ssl' ? 'ssl' : 'tls',
        );

        $transport = Transport::fromDsn($dsn);
        $mailer    = new Mailer($transport);
        $from      = new Address($fromEmail, $fromName);

        return new self($mailer, $from);
    }
}
