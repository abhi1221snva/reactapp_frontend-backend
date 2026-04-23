<?php

namespace App\Http\Controllers;

use App\Model\Client\LeadSource;
use App\Model\Client\LeadSourceField;
use App\Model\Master\LeadSourceWebhookToken;
use App\Models\Client\CrmLeadRecord;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Public webhook endpoint — no JWT required.
 * Security: UUID secret in the URL path (acts as bearer token).
 *
 * POST /webhook/lead-source/{secret}
 *
 * The secret is looked up in master.lead_source_webhook_tokens → resolves client_id + source_id.
 * Payload is validated against crm_lead_source_fields for that source.
 * A crm_leads + crm_lead_values (EAV) record is created.
 */
class LeadSourceWebhookController extends Controller
{

    public function receive(Request $request, string $secret)
    {
        // ── 1. Resolve client + source from master lookup ────────────────────
        $tokenRow = LeadSourceWebhookToken::where('token', $secret)->first();

        if (!$tokenRow) {
            // Avoid timing attacks — always take the same path length
            return response()->json([
                'success' => false,
                'message' => 'Invalid webhook token.',
            ], 401);
        }

        $clientId = (string) $tokenRow->client_id;
        $sourceId = (int)    $tokenRow->source_id;

        // ── 2. Verify the secret still exists in the client DB ────────────────
        $source = LeadSource::on("mysql_$clientId")
            ->where('id', $sourceId)
            ->where('webhook_secret', $secret)
            ->first();

        if (!$source || $source->status != 1) {
            return response()->json([
                'success' => false,
                'message' => 'Webhook is disabled or no longer valid.',
            ], 403);
        }

        // ── 3. Load configured fields for this source ─────────────────────────
        $configuredFields = LeadSourceField::on("mysql_$clientId")
            ->where('lead_source_id', $sourceId)
            ->where('status', '1')
            ->orderBy('display_order')
            ->get();

        // ── 4. Validate payload against configured fields ─────────────────────
        $input  = $request->all();
        $errors = [];

        foreach ($configuredFields as $field) {
            $key   = $field->field_name;
            $value = $input[$key] ?? null;
            $empty = is_null($value) || (is_string($value) && trim($value) === '');

            if ($field->is_required && $empty) {
                $errors[$key] = "The {$field->field_label} field is required.";
                continue;
            }

            if ($empty) {
                continue;
            }

            // Type validation
            if ($field->field_type === 'email' && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
                $errors[$key] = "The {$field->field_label} must be a valid email address.";
            }

            // List value validation
            if (
                $field->field_type === 'list' &&
                !empty($field->allowed_values) &&
                !in_array($value, $field->allowed_values, true)
            ) {
                $errors[$key] = "The value for {$field->field_label} is not in the allowed list.";
            }
        }

        if (!empty($errors)) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed.',
                'errors'  => $errors,
            ], 422);
        }

        // ── 5. Create the lead ────────────────────────────────────────────────
        try {
            // Resolve the client's primary admin to auto-assign the lead
            $adminUser = DB::connection('master')->table('users')
                ->join('roles', 'users.role', '=', 'roles.id')
                ->where('users.parent_id', $clientId)
                ->where('users.is_deleted', 0)
                ->where('users.status', 1)
                ->where('roles.level', '>=', 7)
                ->orderByDesc('roles.level')
                ->select('users.id')
                ->first();

            $adminId = $adminUser ? (int) $adminUser->id : 0;

            $systemData = [
                'lead_status'    => 'new_lead',
                'lead_source_id' => $sourceId,
                'lead_parent_id' => 0,
                'assigned_to'    => $adminId ?: null,
                'created_by'     => $adminId ?: 0,
            ];

            $lead = new CrmLeadRecord($systemData);
            $lead->setConnection("mysql_$clientId");
            $lead->saveOrFail();

            $leadId = $lead->id;

            // ── Save EAV values directly ──────────────────────────────────────
            // We bypass LeadEavService::save() (which whitelists only CORE_FIELDS
            // + crm_labels keys) so that every field mapped by the lead source
            // config is persisted regardless of whether it exists in crm_labels.
            $now = Carbon::now();
            foreach ($configuredFields as $field) {
                $incomingKey = $field->field_name;
                $storageKey  = $field->mapped_field_key ?: $field->field_name;

                if (!array_key_exists($incomingKey, $input)) {
                    continue;
                }

                $val = $input[$incomingKey];

                if ($val === null || $val === '') {
                    continue; // skip empty — don't create blank EAV rows
                }

                DB::connection("mysql_$clientId")
                    ->table('crm_lead_values')
                    ->upsert(
                        [
                            'lead_id'     => $leadId,
                            'field_key'   => $storageKey,
                            'field_value' => trim((string) $val),
                            'created_at'  => $now,
                            'updated_at'  => $now,
                        ],
                        ['lead_id', 'field_key'],
                        ['field_value', 'updated_at']
                    );
            }

            // ── Notify configured users (email / SMS) ────────────────────────
            $this->sendAlerts($source, $clientId, $leadId, $input, $configuredFields);

            Log::info("LeadSourceWebhook: lead created", [
                'client_id' => $clientId,
                'source_id' => $sourceId,
                'lead_id'   => $leadId,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Lead created successfully.',
                'data'    => ['lead_id' => $leadId],
            ], 201);

        } catch (\Throwable $e) {
            Log::error("LeadSourceWebhook: failed to create lead", [
                'client_id' => $clientId,
                'source_id' => $sourceId,
                'error'     => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to process webhook.',
            ], 500);
        }
    }

    /**
     * Send email / SMS alerts to configured users for this lead source.
     */
    private function sendAlerts($source, $clientId, int $leadId, array $input, $configuredFields = null): void
    {
        $notifyEmail   = (bool) ($source->notify_email ?? false);
        $notifySms     = (bool) ($source->notify_sms ?? false);
        $userIds       = $source->notify_user_ids;

        if ((!$notifyEmail && !$notifySms) || empty($userIds)) {
            return;
        }

        // Cast JSON string to array if needed
        if (is_string($userIds)) {
            $userIds = json_decode($userIds, true) ?: [];
        }

        $users = DB::connection('master')->table('users')
            ->whereIn('id', $userIds)
            ->where('is_deleted', 0)
            ->get(['id', 'first_name', 'last_name', 'email', 'mobile']);

        $leadName   = trim(($input['first_name'] ?? '') . ' ' . ($input['last_name'] ?? '')) ?: 'New Lead';
        $sourceName = $source->source_title ?? 'Webhook';

        // Build a label→value map from configured fields for full detail display
        $fieldDetails = [];
        if ($configuredFields) {
            foreach ($configuredFields as $field) {
                $key = $field->field_name;
                $val = $input[$key] ?? null;
                if ($val !== null && $val !== '') {
                    $fieldDetails[$field->field_label] = (string) $val;
                }
            }
        } else {
            // Fallback: use raw input keys prettified
            foreach ($input as $k => $v) {
                if ($v !== null && $v !== '') {
                    $fieldDetails[ucwords(str_replace('_', ' ', $k))] = (string) $v;
                }
            }
        }

        // ── Build email HTML body ───────────────────────────────────────────
        $emailHtml  = '<div style="font-family:Arial,Helvetica,sans-serif;max-width:600px;margin:0 auto">';
        $emailHtml .= '<div style="background:linear-gradient(135deg,#4f46e5,#6366f1);padding:24px 28px;border-radius:12px 12px 0 0">';
        $emailHtml .= '<h2 style="margin:0;color:#fff;font-size:20px">New Lead Received</h2>';
        $emailHtml .= '<p style="margin:6px 0 0;color:#c7d2fe;font-size:13px">Source: ' . e($sourceName) . ' &bull; Lead #' . $leadId . '</p>';
        $emailHtml .= '</div>';
        $emailHtml .= '<div style="background:#ffffff;border:1px solid #e2e8f0;border-top:none;border-radius:0 0 12px 12px;padding:24px 28px">';
        $emailHtml .= '<table style="width:100%;border-collapse:collapse">';
        foreach ($fieldDetails as $label => $value) {
            $emailHtml .= '<tr>';
            $emailHtml .= '<td style="padding:8px 12px 8px 0;border-bottom:1px solid #f1f5f9;color:#64748b;font-size:13px;white-space:nowrap;vertical-align:top">' . e($label) . '</td>';
            $emailHtml .= '<td style="padding:8px 0;border-bottom:1px solid #f1f5f9;color:#1e293b;font-size:13px;font-weight:600">' . e($value) . '</td>';
            $emailHtml .= '</tr>';
        }
        $emailHtml .= '</table>';
        $emailHtml .= '<p style="margin:20px 0 0;font-size:11px;color:#94a3b8">This is an automated notification from your webhook lead source.</p>';
        $emailHtml .= '</div></div>';

        // ── Build SMS body (all fields, line-separated) ─────────────────────
        $smsLines = ["New Lead from {$sourceName} (#{$leadId})"];
        foreach ($fieldDetails as $label => $value) {
            $smsLines[] = "{$label}: {$value}";
        }
        $smsBody = implode("\n", $smsLines);

        // Resolve a "from" number for SMS (first available Twilio or Plivo number)
        $smsFrom = null;
        if ($notifySms) {
            $smsFrom = DB::connection("mysql_{$clientId}")
                ->table('twilio_numbers')
                ->value('phone_number');
            if (!$smsFrom) {
                $smsFrom = DB::connection("mysql_{$clientId}")
                    ->table('plivo_numbers')
                    ->value('phone_number');
            }
        }

        foreach ($users as $user) {
            // Email alert
            if ($notifyEmail && !empty($user->email)) {
                try {
                    $emailService = \App\Services\EmailService::forClientAny((int) $clientId);
                    $subject = "New Lead from {$sourceName}: {$leadName} (#{$leadId})";
                    $emailService->send($user->email, $subject, $emailHtml);
                    Log::info("LeadSourceWebhook: email alert sent", ['user_id' => $user->id, 'lead_id' => $leadId]);
                } catch (\Throwable $e) {
                    Log::warning("LeadSourceWebhook: email alert failed", ['user_id' => $user->id, 'error' => $e->getMessage()]);
                }
            }

            // SMS alert
            if ($notifySms && !empty($user->mobile) && $smsFrom) {
                try {
                    $to = $user->mobile;
                    // Ensure E.164 format
                    if (!str_starts_with($to, '+')) {
                        $to = '+1' . ltrim($to, '1');
                    }

                    // Try Twilio first, then Plivo
                    $sent = false;
                    try {
                        $twilio = \App\Services\TwilioService::forClient((int) $clientId);
                        $twilio->sendSms($to, $smsFrom, $smsBody);
                        $sent = true;
                        Log::info("LeadSourceWebhook: SMS alert sent via Twilio", ['user_id' => $user->id, 'lead_id' => $leadId]);
                    } catch (\Throwable $e) {
                        Log::warning("LeadSourceWebhook: Twilio SMS failed, trying Plivo", ['error' => $e->getMessage()]);
                    }

                    if (!$sent) {
                        try {
                            $plivo = \App\Services\PlivoService::forClient((int) $clientId);
                            $plivo->sendSms($to, $smsFrom, $smsBody);
                            Log::info("LeadSourceWebhook: SMS alert sent via Plivo", ['user_id' => $user->id, 'lead_id' => $leadId]);
                        } catch (\Throwable $e) {
                            Log::warning("LeadSourceWebhook: SMS alert failed (both providers)", ['user_id' => $user->id, 'error' => $e->getMessage()]);
                        }
                    }
                } catch (\Throwable $e) {
                    Log::warning("LeadSourceWebhook: SMS alert failed", ['user_id' => $user->id, 'error' => $e->getMessage()]);
                }
            }
        }
    }
}
