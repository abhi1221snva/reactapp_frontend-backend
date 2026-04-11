<?php

namespace App\Services\Rvm;

use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * RvmSlackNotifier
 *
 * Fire-and-forget Slack incoming-webhook notifier for RVM cutover
 * operator actions. Two public methods — one for single-tenant mode
 * changes, one for fleet rollback-all. Both swallow every exception
 * and log it: a Slack outage must never block an admin mutation.
 *
 * Payload uses Slack's Block Kit so the messages render nicely
 * without any custom formatting on the receiving channel.
 *
 * The webhook URL is read from `config('rvm.slack_webhook_url')`; when
 * empty the notifier short-circuits to a no-op so staging / dev
 * environments don't need an env var set.
 */
class RvmSlackNotifier
{
    public function notifyModeChange(
        int $clientId,
        string $companyName,
        ?string $oldMode,
        string $newMode,
        ?string $liveProvider,
        ?int $liveDailyCap,
        ?string $notes,
        ?string $actorName,
        ?string $actorEmail
    ): void {
        // Only alert for transitions involving `live` — the high-risk
        // boundary. Shadow / dry_run flips are routine and would
        // generate too much noise during a wave cutover.
        $touchesLive = ($oldMode === 'live') || ($newMode === 'live');
        if (!$touchesLive) {
            return;
        }

        $icon = $newMode === 'live' ? ':rocket:' : ':rewind:';
        $headline = sprintf(
            '%s RVM tenant *%s* (#%d) flipped: `%s` → `%s`',
            $icon,
            $companyName,
            $clientId,
            $oldMode ?? 'legacy',
            $newMode
        );

        $fields = [];
        if ($newMode === 'live') {
            $fields[] = ['type' => 'mrkdwn', 'text' => "*Provider*\n" . ($liveProvider ?? '—')];
            $fields[] = ['type' => 'mrkdwn', 'text' => "*Daily cap*\n" . ($liveDailyCap ?? 'uncapped')];
        }
        $fields[] = ['type' => 'mrkdwn', 'text' => "*By*\n" . ($actorName ?? 'unknown')];
        $fields[] = ['type' => 'mrkdwn', 'text' => "*Email*\n" . ($actorEmail ?? '—')];

        $blocks = [
            ['type' => 'section', 'text' => ['type' => 'mrkdwn', 'text' => $headline]],
            ['type' => 'section', 'fields' => $fields],
        ];
        if (!empty($notes)) {
            $blocks[] = [
                'type' => 'context',
                'elements' => [['type' => 'mrkdwn', 'text' => '_' . $this->truncate($notes, 300) . '_']],
            ];
        }

        $this->post($headline, $blocks);
    }

    public function notifyRollbackAll(
        int $affectedCount,
        ?string $actorName,
        ?string $actorEmail
    ): void {
        $headline = sprintf(
            ':rotating_light: *RVM FLEET ROLLBACK-ALL* — %d tenant%s reverted to `legacy`',
            $affectedCount,
            $affectedCount === 1 ? '' : 's'
        );

        $blocks = [
            ['type' => 'section', 'text' => ['type' => 'mrkdwn', 'text' => $headline]],
            [
                'type' => 'section',
                'fields' => [
                    ['type' => 'mrkdwn', 'text' => "*By*\n" . ($actorName ?? 'unknown')],
                    ['type' => 'mrkdwn', 'text' => "*Email*\n" . ($actorEmail ?? '—')],
                ],
            ],
        ];

        $this->post($headline, $blocks);
    }

    // ── Internals ───────────────────────────────────────────────────────

    private function post(string $fallbackText, array $blocks): void
    {
        $url = (string) config('rvm.slack_webhook_url', '');
        if ($url === '') {
            return; // No-op in envs without a webhook configured.
        }

        $timeout = max(1, (int) config('rvm.slack_timeout_seconds', 3));
        $payload = json_encode([
            'text'   => $fallbackText, // notification-bar text
            'blocks' => $blocks,
        ]);

        try {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $payload,
                CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => $timeout,
                CURLOPT_CONNECTTIMEOUT => $timeout,
            ]);
            $body   = curl_exec($ch);
            $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err    = curl_error($ch);
            curl_close($ch);

            if ($err || $status >= 400) {
                Log::warning('RvmSlackNotifier: post failed', [
                    'status' => $status,
                    'error'  => $err,
                    'body'   => is_string($body) ? substr($body, 0, 500) : null,
                ]);
            }
        } catch (Throwable $e) {
            // Never let Slack break an admin operation.
            Log::warning('RvmSlackNotifier: exception', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function truncate(string $s, int $n): string
    {
        if (strlen($s) <= $n) {
            return $s;
        }
        return substr($s, 0, $n - 1) . '…';
    }
}
