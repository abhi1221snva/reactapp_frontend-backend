<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * LeadPdfService
 *
 * Shared PDF rendering logic used by both the authenticated CRM endpoint
 * (LeadController::renderPdf) and the public merchant portal endpoint
 * (PublicApplicationController::renderMerchantPdf).
 *
 * Builds the full placeholder-substitution data map for a lead and renders
 * the active `signature_application` template from crm_custom_templates.
 */
class LeadPdfService
{
    /**
     * Fetch the signature_application template for a client, hydrate it with
     * full lead data, and return the rendered HTML plus metadata.
     *
     * @return array{html: string, lead_name: string, template_id: int, template_name: string}
     * @throws \RuntimeException with HTTP-style code (404 / 500) on failure
     */
    public function renderPdfHtml(int $clientId, int $leadId): array
    {
        $conn = "mysql_{$clientId}";

        $template = DB::connection($conn)
            ->table('crm_custom_templates')
            ->where('custom_type', 'signature_application')
            ->whereNull('deleted_at')
            ->orderBy('id', 'desc')
            ->first();

        if (!$template) {
            throw new \RuntimeException(
                'No application template found. Create one under CRM → PDF Templates.',
                404
            );
        }

        $data = $this->buildLeadData($leadId, $conn, $clientId);

        $firstName = $data['first_name'] ?? '';
        $lastName  = $data['last_name']  ?? '';
        $company   = $data['company_name'] ?? $data['legal_company_name'] ?? '';
        $leadName  = trim("$firstName $lastName") ?: ($company ?: "Lead #{$leadId}");

        $html = $this->applyPlaceholders($template->template_html ?? '', $data);

        return [
            'html'          => $html,
            'lead_name'     => $leadName,
            'template_id'   => $template->id,
            'template_name' => $template->template_name,
        ];
    }

    // ── Private: build the full placeholder-substitution map for a lead ──────

    public function buildLeadData(int $leadId, string $conn, int $clientId): array
    {
        $data = [];

        // 2a. New EAV: crm_lead_values (field_key → field_value)
        $schemaEav = DB::connection($conn)->getSchemaBuilder()->hasTable('crm_lead_values');

        if ($schemaEav) {
            $eavValues = DB::connection($conn)
                ->table('crm_lead_values')
                ->where('lead_id', $leadId)
                ->pluck('field_value', 'field_key')
                ->toArray();
            $data = array_merge($data, $eavValues);
        }

        // 2b. New EAV: crm_leads base record (system cols)
        $schemaLeads = DB::connection($conn)->getSchemaBuilder()->hasTable('crm_leads');

        if ($schemaLeads) {
            $lead = DB::connection($conn)->table('crm_leads')->where('id', $leadId)->first();
            if (!$lead) {
                throw new \RuntimeException('Lead not found', 404);
            }
            // Only merge non-null system cols so they don't overwrite EAV values
            foreach ((array) $lead as $k => $v) {
                if ($v !== null && !isset($data[$k])) {
                    $data[$k] = $v;
                }
            }
        }

        // 2c. Legacy: crm_lead_data with crm_label mapping
        //     label_title_url (template placeholder) → column_name (crm_lead_data col)
        //     e.g. legal_company_name→option_1, amount_requested→option_39, ...
        $schemaOld      = DB::connection($conn)->getSchemaBuilder()->hasTable('crm_lead_data');
        $schemaOldLabel = DB::connection($conn)->getSchemaBuilder()->hasTable('crm_label');

        if ($schemaOld && $schemaOldLabel) {
            $oldLead = DB::connection($conn)->table('crm_lead_data')->where('id', $leadId)->first();
            if ($oldLead) {
                $oldLeadArr = (array) $oldLead;

                // Direct columns first (first_name, last_name, email, etc.)
                foreach ($oldLeadArr as $col => $val) {
                    if (!isset($data[$col]) && $val !== null) {
                        $data[$col] = $val;
                    }
                }

                // Then label_title_url → column_name mapping
                $labels = DB::connection($conn)
                    ->table('crm_label')
                    ->select('label_title_url', 'column_name')
                    ->get();

                foreach ($labels as $lbl) {
                    $key = $lbl->label_title_url;  // e.g. "legal_company_name"
                    $col = $lbl->column_name;       // e.g. "option_1"
                    if (!isset($data[$key]) && array_key_exists($col, $oldLeadArr)) {
                        $data[$key] = $oldLeadArr[$col];
                    }
                }
            }
        }

        // ── Convenience aliases so templates can use common shorthand keys ────
        if (!isset($data['mobile']) && isset($data['phone_number'])) {
            $data['mobile'] = $data['phone_number'];
        }
        if (!isset($data['lead_created_at']) && isset($data['created_at'])) {
            $data['lead_created_at'] = $data['created_at'];
        }
        if (!isset($data['fax'])) {
            $data['fax'] = $data['option_35'] ?? '';
        }
        if (!isset($data['business_phone']) && isset($data['option_38'])) {
            $data['business_phone'] = $data['option_38'];
        }
        if (!isset($data['full_name'])) {
            $data['full_name'] = trim(($data['first_name'] ?? '') . ' ' . ($data['last_name'] ?? ''));
        }

        // ── Agent / Specialist data (from created_by user) ────────────────────
        $createdById = $data['created_by'] ?? null;
        if ($createdById) {
            $agent = DB::table('users')
                ->where('id', (int) $createdById)
                ->select('first_name', 'last_name', 'mobile', 'email', 'company_name', 'logo')
                ->first();

            if ($agent) {
                $agentFullName = trim($agent->first_name . ' ' . $agent->last_name);
                $data['specialist_name']       = $agentFullName;
                $data['specialist_first_name'] = $agent->first_name ?? '';
                $data['specialist_last_name']  = $agent->last_name  ?? '';
                $data['specialist_phone']      = $agent->mobile     ?? '';
                $data['specialist_mobile']     = $agent->mobile     ?? '';
                $data['specialist_email']      = $agent->email      ?? '';
                $data['specialist_fax']        = '';

                $data['company_name_agent'] = $agent->company_name ?? '';

                if (!empty($agent->logo)) {
                    $logoPath = public_path('logo/' . $agent->logo);
                    if (file_exists($logoPath)) {
                        $mime             = mime_content_type($logoPath) ?: 'image/png';
                        $b64              = base64_encode(file_get_contents($logoPath));
                        $data['company_logo'] = '<img src="data:' . $mime . ';base64,' . $b64 . '" style="max-height:80px;max-width:200px;" alt="Logo">';
                    } else {
                        $data['company_logo'] = '<img src="' . env('APP_URL') . '/logo/' . $agent->logo . '" style="max-height:80px;max-width:200px;" alt="Logo">';
                    }
                } else {
                    $data['company_logo'] = '';
                }
            }
        }

        foreach (['specialist_name', 'specialist_phone', 'specialist_mobile', 'specialist_email', 'specialist_fax', 'specialist_first_name', 'specialist_last_name', 'company_logo', 'company_name_agent'] as $k) {
            if (!isset($data[$k])) $data[$k] = '';
        }

        // ── Company details from crm_system_setting (client DB) ───────────────
        try {
            $officeSetting = DB::connection($conn)
                ->table('crm_system_setting')
                ->orderBy('id')
                ->first();

            if ($officeSetting) {
                $data['office_name']    = $officeSetting->company_name    ?? '';
                $data['office_email']   = $officeSetting->company_email   ?? '';
                $data['office_phone']   = $officeSetting->company_phone   ?? '';
                $data['office_address'] = $officeSetting->company_address ?? '';
                $data['office_city']    = $officeSetting->city            ?? '';
                $data['office_state']   = $officeSetting->state           ?? '';
                $data['office_zip']     = $officeSetting->zipcode         ?? '';
                $data['office_domain']  = $officeSetting->company_domain  ?? '';
                $data['company_name']   = $officeSetting->company_name    ?? '';

                if (empty($data['company_name_agent'])) {
                    $data['company_name_agent'] = $officeSetting->company_name ?? '';
                }

                $buildLogoImg = function (string $logo) use ($clientId): string {
                    if (empty($logo)) return '';
                    $style = 'max-height:80px;max-width:200px;display:block;';
                    if (str_starts_with($logo, 'http')) {
                        return '<img src="' . htmlspecialchars($logo) . '" style="' . $style . '" alt="Logo">';
                    }
                    $logoPath = \App\Services\TenantStorageService::getPath($clientId, 'company') . '/' . $logo;
                    if (!file_exists($logoPath)) {
                        $logoPath = public_path('logo/' . $logo);
                    }
                    if (file_exists($logoPath)) {
                        $mime = mime_content_type($logoPath) ?: 'image/png';
                        $b64  = base64_encode(file_get_contents($logoPath));
                        return '<img src="data:' . $mime . ';base64,' . $b64 . '" style="' . $style . '" alt="Logo">';
                    }
                    return '<img src="' . rtrim(env('APP_URL'), '/') . '/public/tenant/' . $clientId . '/logo" style="' . $style . '" alt="Logo">';
                };

                $data['office_logo']  = !empty($officeSetting->logo) ? $buildLogoImg((string) $officeSetting->logo) : '';
                $data['company_logo'] = $data['office_logo'] ?: ($data['company_logo'] ?? '');
            }
        } catch (\Throwable $ignored) {}

        foreach (['office_name', 'office_email', 'office_phone', 'office_address', 'office_city', 'office_state', 'office_zip', 'office_domain', 'office_logo', 'company_name', 'company_logo'] as $k) {
            if (!isset($data[$k])) $data[$k] = '';
        }

        // ── unique_url: use DB value as-is; normalise to anchor tag ──────────
        Log::info('[LeadPdfService.buildLeadData] DB_unique_url', [
            'lead_id'    => $leadId,
            'unique_url' => $data['unique_url'] ?? null,
        ]);

        $rawUniqueUrl = (string) ($data['unique_url'] ?? '');
        if (!empty($rawUniqueUrl)) {
            if (strpos($rawUniqueUrl, '<a ') !== false) {
                $data['unique_url'] = $rawUniqueUrl;
            } else {
                $data['unique_url'] = '<a href="' . htmlspecialchars($rawUniqueUrl, ENT_QUOTES, 'UTF-8') . '">Click Here</a>';
            }
        } else {
            $token = $data['unique_token'] ?? $data['lead_token'] ?? null;
            if ($token) {
                $plainUrl = $this->getPortalBaseUrl($clientId)
                    . '/merchant/customer/app/index/' . $clientId . '/' . $leadId . '/' . $token;
                $data['unique_url'] = '<a href="' . $plainUrl . '">Click Here</a>';
                try {
                    DB::connection($conn)->table('crm_leads')->where('id', $leadId)
                        ->update(['unique_url' => $data['unique_url']]);
                } catch (\Throwable $ignored) {}
            } else {
                $data['unique_url'] = '';
            }
        }

        return $data;
    }

    // ── Substitute [[key]] and [key] placeholders in an HTML string ───────────

    public function applyPlaceholders(string $text, array $data): string
    {
        foreach ($data as $key => $value) {
            $val  = (string) ($value ?? '');
            $text = str_replace("[[{$key}]]", $val, $text);
            $text = str_replace("[{$key}]",   $val, $text);
        }
        $text = preg_replace('/\[\[[^\]]*\]\]/', '', $text);
        $text = preg_replace('/\[[a-z0-9_]+\]/i', '', $text);
        return $text;
    }

    // ── Resolve portal base URL (mirrors Controller::getPortalBaseUrl) ────────

    private function getPortalBaseUrl(int $clientId): string
    {
        $domain = DB::connection("mysql_{$clientId}")
            ->table('crm_system_setting')
            ->orderBy('id')
            ->value('company_domain');

        if (empty($domain)) {
            return rtrim(env('APP_FRONTEND_URL', env('APP_URL', '')), '/');
        }

        return rtrim($domain, '/');
    }
}
