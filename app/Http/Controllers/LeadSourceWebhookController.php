<?php

namespace App\Http\Controllers;

use App\Model\Client\LeadSource;
use App\Model\Client\LeadSourceField;
use App\Model\Master\LeadSourceWebhookToken;
use App\Models\Client\CrmLeadRecord;
use App\Services\LeadEavService;
use Illuminate\Http\Request;
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
    protected LeadEavService $eavService;

    public function __construct()
    {
        $this->eavService = new LeadEavService();
    }

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
            $systemData = [
                'lead_status'    => 'new_lead',
                'lead_source_id' => $sourceId,
                'lead_parent_id' => 0,
                'created_by'     => 0, // system/webhook
            ];

            $lead = new CrmLeadRecord($systemData);
            $lead->setConnection("mysql_$clientId");
            $lead->saveOrFail();

            $leadId = $lead->id;

            // Build EAV payload — use mapped_field_key (if set) as the storage key
            // so the value lands in the correct CRM field.
            $eavInput = [];
            foreach ($configuredFields as $field) {
                $incomingKey = $field->field_name;               // key in the webhook payload
                $storageKey  = $field->mapped_field_key          // desired CRM EAV key
                    ?: $field->field_name;                       // fallback: same as incoming key
                if (array_key_exists($incomingKey, $input)) {
                    $eavInput[$storageKey] = $input[$incomingKey];
                }
            }

            $this->eavService->save($clientId, $leadId, $eavInput);

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
}
