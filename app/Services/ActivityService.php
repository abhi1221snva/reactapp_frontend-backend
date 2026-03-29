<?php

namespace App\Services;

use App\Model\Client\CrmLeadActivity;
use Illuminate\Support\Facades\Log;

/**
 * Central helper for logging lead activity.
 *
 * All activity writes should go through ActivityService::log() to ensure
 * consistent structure, error handling, and truncation of long values.
 */
class ActivityService
{
    /**
     * Log a lead activity entry.
     *
     * @param  string       $clientId    Tenant client ID
     * @param  int          $leadId      Lead ID
     * @param  string       $type        Activity type (note_added, lender_api_result, etc.)
     * @param  string       $subject     Short, human-readable title (max 500 chars)
     * @param  string|null  $body        Longer description or multi-line notes
     * @param  array        $meta        Structured metadata stored as JSON
     * @param  int          $userId      User who triggered the action (0 = system)
     * @param  string       $sourceType  Origin: api | manual | pipeline | lender_api | crm_notifications
     * @param  int|null     $sourceId    FK to originating row if applicable
     * @param  bool         $isPinned    Whether to pin this entry immediately
     */
    public static function log(
        string  $clientId,
        int     $leadId,
        string  $type,
        string  $subject,
        ?string $body       = null,
        array   $meta       = [],
        int     $userId     = 0,
        string  $sourceType = 'api',
        ?int    $sourceId   = null,
        bool    $isPinned   = false
    ): ?CrmLeadActivity {
        try {
            $a = new CrmLeadActivity();
            $a->setConnection("mysql_{$clientId}");
            $a->lead_id       = $leadId;
            $a->user_id       = $userId ?: null;
            $a->activity_type = $type;
            $a->subject       = mb_substr($subject, 0, 500);
            $a->body          = $body;
            $a->meta          = !empty($meta) ? $meta : null;
            $a->source_type   = $sourceType;
            $a->source_id     = $sourceId;
            $a->is_pinned     = $isPinned;
            $a->save();
            return $a;
        } catch (\Throwable $e) {
            Log::error('ActivityService::log failed', [
                'client_id' => $clientId,
                'lead_id'   => $leadId,
                'type'      => $type,
                'error'     => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Build a readable one-line subject for a lender API activity.
     *
     * Examples:
     *   "CAN Capital — Application submitted successfully"
     *   "OnDeck Capital — Application submitted (2 docs sent)"
     *   "OnDeck Capital — Validation error (2 fields)"
     *   "Credibly — API error (HTTP 422)"
     *   "Headway Capital — Connection timeout"
     */
    public static function lenderApiSubject(
        string  $lenderName,
        bool    $success,
        ?string $error            = null,
        array   $validationErrors = [],
        ?int    $responseCode     = null,
        ?array  $docUpload        = null
    ): string {
        $prefix = "{$lenderName} — ";

        if (!empty($validationErrors)) {
            $n = count($validationErrors);
            return $prefix . "Validation error ({$n} field" . ($n > 1 ? 's' : '') . ')';
        }

        if ($success) {
            if ($docUpload !== null) {
                $uploaded = count((array)($docUpload['uploaded'] ?? []));
                $failed   = count((array)($docUpload['failed']   ?? []));
                if ($failed > 0 && $uploaded === 0) {
                    return $prefix . 'Application submitted (documents failed)';
                }
                if ($failed > 0) {
                    return $prefix . "Application submitted ({$uploaded} doc(s) sent, {$failed} failed)";
                }
                if ($uploaded > 0) {
                    return $prefix . "Application submitted ({$uploaded} doc(s) sent)";
                }
            }
            return $prefix . 'Application submitted successfully';
        }

        // Failure cases
        if ($error && stripos($error, 'timeout') !== false) {
            return $prefix . 'Connection timeout';
        }
        if ($responseCode) {
            return $prefix . "API error (HTTP {$responseCode})";
        }
        return $prefix . 'API submission failed';
    }

    /**
     * Build a readable multi-line body for a lender API activity.
     * Returns human-readable text — NO raw JSON.
     */
    public static function lenderApiBody(
        bool    $success,
        ?string $error            = null,
        array   $validationErrors = [],
        ?int    $responseCode     = null,
        ?int    $durationMs       = null,
        ?array  $docUpload        = null
    ): ?string {
        $lines = [];

        if (!empty($validationErrors)) {
            foreach ($validationErrors as $ve) {
                $lines[] = '• ' . $ve;
            }
            return implode("\n", $lines);
        }

        if ($success) {
            if ($responseCode) {
                $lines[] = "HTTP {$responseCode} — success";
            }
            if ($durationMs !== null) {
                $lines[] = "Completed in {$durationMs}ms";
            }
            if ($docUpload !== null) {
                $up = count((array)($docUpload['uploaded'] ?? []));
                $fl = count((array)($docUpload['failed']   ?? []));
                if ($up > 0 || $fl > 0) {
                    $lines[] = "Documents: {$up} sent" . ($fl > 0 ? ", {$fl} failed" : '');
                }
            }
        } else {
            if ($error) {
                $lines[] = $error;
            }
            if ($responseCode) {
                $lines[] = "HTTP {$responseCode}";
            }
        }

        return !empty($lines) ? implode("\n", $lines) : null;
    }
}
