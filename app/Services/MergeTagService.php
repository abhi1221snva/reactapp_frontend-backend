<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Resolves [[key]] and {{key}} merge-tag placeholders in text using lead data.
 *
 * Data sources (merged in order, later entries win):
 *  1. System columns from crm_leads
 *  2. EAV values from crm_lead_values
 *  3. Legacy EAV from crm_lead_field_values (fallback when new table is empty)
 */
class MergeTagService
{
    /**
     * Resolve all [[key]] and {{key}} merge tags in $text using the given lead's data.
     *
     * @param  int|string $clientId
     * @param  int        $leadId
     * @param  string     $text     The text containing merge tags
     * @param  int|null   $agentId  Optional: also resolve [agent_field] single-bracket tags
     * @return string
     */
    public function resolve(string $clientId, int $leadId, string $text, ?int $agentId = null): string
    {
        if (empty(trim($text))) {
            return $text;
        }

        $data = $this->loadLeadData($clientId, $leadId);

        // Replace [[key]] placeholders
        $text = preg_replace_callback('/\[\[(\w+)\]\]/', function ($m) use ($data) {
            $val = $data[$m[1]] ?? null;
            return ($val !== null && $val !== '') ? (string) $val : $m[0];
        }, $text);

        // Replace {{key}} placeholders (alternative format)
        $text = preg_replace_callback('/\{\{(\w+)\}\}/', function ($m) use ($data) {
            $val = $data[$m[1]] ?? null;
            return ($val !== null && $val !== '') ? (string) $val : $m[0];
        }, $text);

        // Optionally resolve [agent_field] single-bracket tags from the assigned agent's User record
        if ($agentId) {
            try {
                $agent = \App\Model\User::where('id', $agentId)
                    ->where('parent_id', $clientId)
                    ->first();
                if ($agent) {
                    foreach ($agent->toArray() as $k => $v) {
                        $text = str_replace("[{$k}]", (string) ($v ?? ''), $text);
                    }
                }
            } catch (\Throwable $e) {
                // Non-fatal — agent field resolution is best-effort
            }
        }

        return $text;
    }

    /**
     * Load all data for a lead: system columns + EAV values.
     *
     * @return array<string, mixed>
     */
    private function loadLeadData(string $clientId, int $leadId): array
    {
        $conn = "mysql_{$clientId}";
        $data = [];

        // 1. System columns from crm_leads (new EAV system)
        try {
            $lead = DB::connection($conn)
                ->table('crm_leads')
                ->where('id', $leadId)
                ->first();
            if ($lead) {
                $data = array_filter((array) $lead, fn ($v) => $v !== null);
            }
        } catch (\Throwable $e) {
            // Table may not exist on legacy setup — continue
        }

        // 2. New EAV values from crm_lead_values
        try {
            $eavRows = DB::connection($conn)
                ->table('crm_lead_values')
                ->where('lead_id', $leadId)
                ->get(['field_key', 'field_value']);
            foreach ($eavRows as $row) {
                if ($row->field_value !== null && $row->field_value !== '') {
                    $data[$row->field_key] = $row->field_value;
                }
            }
        } catch (\Throwable $e) {
            // Table may not exist — continue
        }

        // 3. Legacy EAV from crm_lead_field_values (old column_name/value_text format)
        //    Only used as fallback when the new tables returned nothing
        if (empty($data)) {
            try {
                $legacyRows = DB::connection($conn)
                    ->table('crm_lead_field_values')
                    ->where('lead_id', $leadId)
                    ->get(['column_name', 'value_text']);
                foreach ($legacyRows as $row) {
                    if ($row->value_text !== null && $row->value_text !== '') {
                        $data[$row->column_name] = $row->value_text;
                    }
                }
            } catch (\Throwable $e) {
                // Table may not exist — continue
            }
        }

        return $data;
    }
}
