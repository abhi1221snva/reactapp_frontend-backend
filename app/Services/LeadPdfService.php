<?php

namespace App\Services;

use Dompdf\Dompdf;
use Dompdf\Options;
use Carbon\Carbon;
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

        // Strip the "Second Owner" section from the rendered HTML when no Owner 2
        // data exists.  The CRM template contains hardcoded table structure (headers,
        // labels, rows) that remains even after empty [[owner_2_*]] placeholders are
        // removed.  We detect this by checking whether any owner_2_ key carried a
        // non-empty value in the data map.
        $hasOwner2Data = false;
        foreach ($data as $k => $v) {
            if (str_starts_with((string) $k, 'owner_2_') && $v !== '' && $v !== null) {
                $hasOwner2Data = true;
                break;
            }
        }
        if (!$hasOwner2Data) {
            $html = $this->stripOwner2Sections($html);
        }

        return [
            'html'          => $html,
            'lead_name'     => $leadName,
            'first_name'    => $firstName,
            'last_name'     => $lastName,
            'template_id'   => $template->id,
            'template_name' => $template->template_name,
        ];
    }

    /**
     * Generate a sanitized PDF filename from lead first/last name.
     *
     * Rules:
     *  - Lowercase
     *  - Spaces and special chars → underscore
     *  - Consecutive underscores collapsed to one
     *  - Leading/trailing underscores stripped
     *  - Format: {first}_{last}_application.pdf
     *           {first}_application.pdf  (if no last name)
     *           application.pdf           (if both empty)
     */
    public static function pdfFilename(?string $firstName, ?string $lastName): string
    {
        $sanitize = static function (?string $s): string {
            $s = trim((string) $s);
            // Decompose accented chars (é→e+accent) then strip the accent marks
            if (class_exists('Normalizer')) {
                $s = \Normalizer::normalize($s, \Normalizer::FORM_D);
                $s = preg_replace('/[\x{0300}-\x{036f}]/u', '', $s);
            }
            $s = strtolower($s);
            $s = preg_replace('/[^a-z0-9]+/', '_', $s);
            return trim($s, '_');
        };

        $first = $sanitize($firstName);
        $last  = $sanitize($lastName);

        if ($first !== '' && $last !== '') return "{$first}_{$last}_application.pdf";
        if ($first !== '')                  return "{$first}_application.pdf";
        if ($last  !== '')                  return "{$last}_application.pdf";
        return 'application.pdf';
    }

    /**
     * Resolve first_name / last_name for a lead from EAV (crm_lead_values),
     * falling back to system columns on the crm_leads row if present.
     * Used by fallback download paths where no CRM template exists.
     *
     * @return array{first_name: string|null, last_name: string|null}
     */
    public function resolveLeadName(int $clientId, int $leadId): array
    {
        $conn  = "mysql_{$clientId}";
        $names = ['first_name' => null, 'last_name' => null];

        // EAV lookup (crm_lead_values) — primary source
        if (DB::connection($conn)->getSchemaBuilder()->hasTable('crm_lead_values')) {
            $rows = DB::connection($conn)->table('crm_lead_values')
                ->where('lead_id', $leadId)
                ->whereIn('field_key', ['first_name', 'last_name'])
                ->pluck('field_value', 'field_key');
            $names['first_name'] = $rows['first_name'] ?? null;
            $names['last_name']  = $rows['last_name']  ?? null;
        }

        // System-column fallback (legacy crm_lead_data or crm_leads with cols)
        if (empty($names['first_name']) && empty($names['last_name'])) {
            $tables = ['crm_leads', 'crm_lead_data'];
            foreach ($tables as $tbl) {
                if (!DB::connection($conn)->getSchemaBuilder()->hasTable($tbl)) continue;
                $cols = DB::connection($conn)->getSchemaBuilder()->getColumnListing($tbl);
                if (!in_array('first_name', $cols, true)) continue;
                $row = DB::connection($conn)->table($tbl)->where('id', $leadId)->first();
                if ($row) {
                    $names['first_name'] = $row->first_name ?? null;
                    $names['last_name']  = $row->last_name  ?? null;
                    break;
                }
            }
        }

        return $names;
    }

    /**
     * Same as renderPdfHtml() but returns binary PDF bytes via Dompdf.
     * Used by download endpoints — forces a file download instead of opening
     * an HTML preview in the browser.
     *
     * @return array{pdf: string, filename: string}
     * @throws \RuntimeException with HTTP-style code on failure
     */
    public function renderPdfBinary(int $clientId, int $leadId): array
    {
        $result   = $this->renderPdfHtml($clientId, $leadId);
        $binary   = $this->htmlToPdfBytes($result['html']);
        $filename = self::pdfFilename($result['first_name'] ?? null, $result['last_name'] ?? null);

        return [
            'pdf'      => $binary,
            'filename' => $filename,
        ];
    }

    /**
     * Convert an HTML string to PDF bytes using Dompdf.
     * Prepends a compact CSS override so any template (custom or fallback)
     * fits on a single A4 page regardless of its own font-size declarations.
     */
    public function htmlToPdfBytes(string $html): string
    {
        // Compact CSS overrides — forces small fonts, tight page margins,
        // and full-width layout on every template.
        // !important wins over inline styles and template CSS.
        $compact = '<style>'
            . '@page { size: A4 portrait; margin: 8mm 10mm; }'
            . '* { box-sizing: border-box; }'
            . 'html, body { width: 100% !important; margin: 0 !important; padding: 0 !important; }'
            . 'body { font-size: 8px !important; line-height: 1.3 !important; }'
            . '.container { width: 100% !important; max-width: none !important; margin: 0 !important; padding: 0 !important; }'
            . 'table { width: 100% !important; font-size: 8px !important; border-collapse: collapse; }'
            . 'th, td { font-size: 8px !important; padding: 2px 4px !important; line-height: 1.3 !important; }'
            . 'h1, h2, h3, h4 { font-size: 10px !important; margin: 2px 0 !important; padding: 2px 0 !important; }'
            . 'p, div, span { line-height: 1.3 !important; }'
            . 'p { margin: 1px 0 !important; }'
            . '</style>';

        // Late override — placed AFTER template CSS so it wins by source order.
        // Neutralises the old seeder template's blanket "th, td { width: 50%; }"
        // which forces every cell to half-width.  Uses normal specificity (no
        // !important) so inline style="width:25%" on specific cells still works.
        $lateOverride = '<style>th, td { width: auto; }</style>';

        // Ensure proper HTML document structure for DOMPDF.
        // Without <!DOCTYPE html> and <html><body>, DOMPDF enters quirks mode
        // and may restrict content to partial page width.
        if (stripos($html, '<html') === false) {
            // Template is a bare HTML fragment — wrap in full document structure.
            // Compact CSS goes in <head> (processed first), template content in
            // <body>, late override at end of body (wins by source order).
            $html = '<!DOCTYPE html><html><head><meta charset="UTF-8">'
                . $compact
                . '</head><body>'
                . $html
                . $lateOverride
                . '</body></html>';
        } else {
            // Template already has HTML structure — inject CSS into it.
            if (preg_match('#<head[^>]*>#i', $html)) {
                $html = preg_replace('#(<head[^>]*>)#i', '$1' . $compact, $html);
            } else {
                $html = preg_replace('#(<html[^>]*>)#i', '$1<head>' . $compact . '</head>', $html);
            }
            // Inject late override before </body> (or append if no closing tag)
            if (stripos($html, '</body>') !== false) {
                $html = str_ireplace('</body>', $lateOverride . '</body>', $html);
            } else {
                $html .= $lateOverride;
            }
        }

        $opts = new Options();
        $opts->set('isRemoteEnabled', false);    // no external HTTP — all images are data URIs
        $opts->set('isHtml5ParserEnabled', true);
        $opts->set('defaultFont', 'DejaVu Sans');
        $opts->set('chroot', storage_path());    // restrict FS access to storage dir

        $dompdf = new Dompdf($opts);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        return $dompdf->output();
    }

    // ── Private: build the full placeholder-substitution map for a lead ──────

    public function buildLeadData(int $leadId, string $conn, int $clientId): array
    {
        $data = [];
        $eavValues = [];

        // 2a. New EAV: crm_lead_values (field_key → field_value)
        $schemaEav = DB::connection($conn)->getSchemaBuilder()->hasTable('crm_lead_values');

        if ($schemaEav) {
            $eavValues = DB::connection($conn)
                ->table('crm_lead_values')
                ->where('lead_id', $leadId)
                ->pluck('field_value', 'field_key')
                ->toArray();
            $data = array_merge($data, $eavValues);

            // Create aliases from label_name so templates using friendly keys
            // (e.g. [[owner_2_first_name]]) resolve to option_* EAV values.
            // The field_key is the canonical source (what the form saves to), so its
            // value OVERRIDES any stale EAV entry stored under the alias key.
            $schemaLabels = DB::connection($conn)->getSchemaBuilder()->hasTable('crm_labels');
            if ($schemaLabels) {
                $crmLabels = DB::connection($conn)
                    ->table('crm_labels')
                    ->select('field_key', 'label_name')
                    ->get();

                foreach ($crmLabels as $lbl) {
                    // Normalize "Owner 2 First Name" → "owner_2_first_name"
                    $alias = strtolower(trim($lbl->label_name));
                    $alias = preg_replace('/[^a-z0-9]+/', '_', $alias);
                    $alias = trim($alias, '_');

                    if ($alias && $alias !== $lbl->field_key && isset($data[$lbl->field_key])) {
                        $data[$alias] = $data[$lbl->field_key];
                    }
                }
            }
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

        // If EAV is active and Owner 2 was removed (no EAV rows), strip legacy Owner 2 data
        if ($schemaEav && !empty($eavValues)) {
            $hasOwner2InEav = false;
            foreach ($eavValues as $k => $v) {
                if (str_starts_with($k, 'owner_2_') && $v !== '' && $v !== null) {
                    $hasOwner2InEav = true;
                    break;
                }
            }
            if (!$hasOwner2InEav) {
                foreach (array_keys($data) as $k) {
                    if (str_starts_with($k, 'owner_2_') || preg_match('/^option_7(3[4-9]|4[0-5])$/', $k)) {
                        unset($data[$k]);
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
        if (!isset($data['owner_zipcode'])) {
            $data['owner_zipcode'] = $data['option_46'] ?? $data['zip'] ?? '';
        }
        if (!isset($data['dob']) && isset($data['date_of_birth'])) {
            $data['dob'] = $data['date_of_birth'];
        }
        if (!isset($data['dob'])) {
            $data['dob'] = $data['option_40'] ?? '';
        }
        if (!isset($data['owner_email'])) {
            $data['owner_email'] = $data['email'] ?? '';
        }
        if (!isset($data['home_address'])) {
            $data['home_address'] = $data['option_37'] ?? $data['address'] ?? '';
        }
        if (!isset($data['home_city'])) {
            $data['home_city'] = $data['option_36'] ?? $data['city'] ?? '';
        }
        if (!isset($data['home_state'])) {
            $data['home_state'] = $data['option_34'] ?? $data['state'] ?? '';
        }

        // ── Case-insensitive aliases: duplicate lowercase keys as mixed-case
        //    so templates using [[Business_State]] match data['business_state']
        $caseMirror = [];
        foreach ($data as $k => $v) {
            $lower = strtolower($k);
            if ($lower !== $k && !isset($data[$lower])) {
                $caseMirror[$lower] = $v;
            } elseif ($lower === $k) {
                // Build ucfirst-style variants: business_state → Business_State
                $ucKey = implode('_', array_map('ucfirst', explode('_', $k)));
                if ($ucKey !== $k && !isset($data[$ucKey])) {
                    $caseMirror[$ucKey] = $v;
                }
            }
        }
        $data = array_merge($data, $caseMirror);

        // ── Agent / Specialist data (prefer assigned_to, fall back to created_by)
        $agentUserId = $data['assigned_to'] ?? $data['created_by'] ?? null;
        if ($agentUserId) {
            $agent = DB::table('users')
                ->where('id', (int) $agentUserId)
                ->select('first_name', 'last_name', 'mobile', 'email', 'company_name', 'logo', 'timezone')
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
                $plainUrl = $this->getPortalBaseUrl($clientId) . '/merchant/' . $token;
                $data['unique_url'] = '<a href="' . $plainUrl . '">Click Here</a>';
                try {
                    DB::connection($conn)->table('crm_leads')->where('id', $leadId)
                        ->update(['unique_url' => $data['unique_url']]);
                } catch (\Throwable $ignored) {}
            } else {
                $data['unique_url'] = '';
            }
        }

        // ── Signature images → inline <img> tags ─────────────────────────────
        foreach (['signature_image', 'owner_2_signature_image'] as $sigKey) {
            if (isset($data[$sigKey])) {
                $data[$sigKey] = $this->resolveSignatureImg($clientId, (string) $data[$sigKey]);
            }
        }

        // ── Signature dates — ensure they are proper dates, never image paths ──
        // Use agent's timezone for date display.
        $agentTz = 'America/New_York'; // default
        if (isset($agent) && !empty($agent->timezone)) {
            try { new \DateTimeZone($agent->timezone); $agentTz = $agent->timezone; } catch (\Throwable $ignored) {}
        }

        $dateMap = [
            'signature_date'           => 'signature_image',
            'owner_2_signature_date'   => 'owner_2_signature_image',
        ];
        foreach ($dateMap as $dateKey => $sigKey) {
            $currentVal = $data[$dateKey] ?? '';
            // If the date field looks like a file path or <img> tag, it's corrupted — clear it
            // But don't clear actual dates like "04/22/2026" — only clear paths/HTML
            if (str_contains((string) $currentVal, '<img') || str_contains((string) $currentVal, '.png')
                || (str_contains((string) $currentVal, '/') && preg_match('#[a-z].*/#i', (string) $currentVal))) {
                $currentVal = '';
            }
            if (!empty($currentVal)) {
                // Date is already stored in the agent's timezone by updateSignatureDates().
                // Do NOT apply setTimezone() again — that shifts the date backwards by a day
                // when the server TZ differs from the agent TZ (e.g. 04/23 00:00 UTC → 04/22 20:00 EST).
                try {
                    $data[$dateKey] = Carbon::parse((string) $currentVal)->format('m/d/Y');
                } catch (\Throwable $e) {
                    $data[$dateKey] = $currentVal;
                }
            } elseif (!empty($data[$sigKey]) && str_contains((string) $data[$sigKey], '<img')) {
                // Signature exists but no date stored — use now in agent tz
                $data[$dateKey] = Carbon::now($agentTz)->format('m/d/Y');
            } else {
                $data[$dateKey] = '';
            }
        }

        // Also format lead_created_at in agent timezone
        if (!empty($data['created_at'])) {
            try {
                $data['lead_created_at'] = Carbon::parse((string) $data['created_at'])->setTimezone($agentTz)->format('m/d/Y');
            } catch (\Throwable $ignored) {}
        }

        return $data;
    }

    /**
     * Convert a stored signature path into an inline <img> HTML tag.
     *
     * Stored value is a relative path like "leads/19/signatures/signature_xxx.png".
     * We try two storage locations (new TenantStorageService path, then legacy public
     * disk), embed the file as a base64 data URI, and return the <img> element so
     * that [[signature_image]] renders as an actual image in any PDF template.
     *
     * Returns '' (empty string) when no path is provided.
     * Returns a "No signature on file" notice when the path exists but the file
     * cannot be found — avoids showing raw file paths in the rendered output.
     */
    private function resolveSignatureImg(int $clientId, string $rawValue): string
    {
        if (empty($rawValue)) {
            return '';
        }

        // Already resolved (data URI or full <img> tag) — pass through unchanged.
        if (str_starts_with($rawValue, 'data:') || str_contains($rawValue, '<img')) {
            return $rawValue;
        }

        // Strip any accidental leading slash.
        $relPath = ltrim($rawValue, '/');

        // Try new per-tenant storage: storage/app/clients/client_{id}/leads/…
        $absPath = \App\Services\TenantStorageService::getPath($clientId, $relPath);

        // Fallback: legacy public disk storage/app/public/…
        if (!file_exists($absPath)) {
            $absPath = storage_path('app/public/' . $relPath);
        }

        if (!file_exists($absPath)) {
            return '<span style="color:#94a3b8;font-size:12px;font-style:italic;">No signature on file</span>';
        }

        try {
            $mime = mime_content_type($absPath) ?: 'image/png';
            $b64  = base64_encode(file_get_contents($absPath));
            return '<img src="data:' . $mime . ';base64,' . $b64
                . '" style="max-height:50px;max-width:160px;display:block;" alt="Signature" />';
        } catch (\Throwable $e) {
            Log::warning("[LeadPdfService] Could not embed signature for lead (client {$clientId}): " . $e->getMessage());
            return '<span style="color:#94a3b8;font-size:12px;font-style:italic;">Signature unavailable</span>';
        }
    }

    // ── Substitute [[key]] and [key] placeholders in an HTML string ───────────

    public function applyPlaceholders(string $text, array $data): string
    {
        // Legacy underscore-wrapped placeholders (used in older templates)
        if (isset($data['company_logo'])) {
            $text = str_replace('_logo_', (string) $data['company_logo'], $text);
        }
        if (isset($data['company_name'])) {
            $text = str_replace('_company_name_', (string) $data['company_name'], $text);
        }

        foreach ($data as $key => $value) {
            $val  = (string) ($value ?? '');
            $text = str_replace("[[{$key}]]", $val, $text);
            $text = str_replace("[{$key}]",   $val, $text);
        }
        $text = preg_replace('/\[\[[^\]]*\]\]/', '', $text);
        $text = preg_replace('/\[[a-z0-9_]+\]/i', '', $text);
        return $text;
    }

    // ── Remove Owner 2 / Second Owner sections from rendered template HTML ────

    /**
     * Strip "Second Owner" information and co-applicant signature columns from
     * the rendered CRM template HTML.  Called when no owner_2_* data exists so
     * the PDF does not display an empty Second Owner block.
     */
    private function stripOwner2Sections(string $html): string
    {
        // 1. Remove "Second Owner" / "Owner 2" <thead> + following <tbody> blocks.
        //    Handles both old-style (inline in main table) and new-style (nested in
        //    owner-2-col) templates.
        $html = preg_replace(
            '#<thead>\s*<tr>\s*<th[^>]*>[^<]*(?:Second\s+Owner|Owner\s*2)[^<]*</th>\s*</tr>\s*</thead>\s*<tbody>.*?</tbody>#is',
            '',
            $html
        );

        // 2. Remove the owner-2-col <td> from the side-by-side owner layout.
        //    The cell contains a nested <table> with Owner 2 fields.
        $html = preg_replace(
            '#<td[^>]*class=["\']owner-2-col["\'][^>]*>.*?</table>\s*</td>#is',
            '',
            $html
        );

        // 3. Expand owner-1-col from 50% to 100% width when Owner 2 is removed,
        //    so the single owner section fills the full page width.
        $html = preg_replace(
            '#(<td[^>]*class=["\']owner-1-col["\'][^>]*style="[^"]*?)width\s*:\s*50%#is',
            '$1width:100%',
            $html
        );
        $html = preg_replace(
            '#(<td[^>]*class=["\']owner-1-col["\'][^>]*style="[^"]*?)padding-right\s*:\s*\d+px#is',
            '$1padding-right:0',
            $html
        );

        // 4. Remove co-applicant signature label cells from the signature table.
        //    Removes the "Second Owner" label <td> plus the adjacent "Date" <td>.
        $html = preg_replace(
            '#<td[^>]*>[^<]*(?:Second\s+Owner|Co-?Applicant)[^<]*</td>\s*<td[^>]*>\s*Date\s*</td>#is',
            '',
            $html
        );
        // Fallback: remove the label cell alone if "Date" didn't immediately follow
        $html = preg_replace(
            '#<td[^>]*>[^<]*(?:Second\s+Owner|Co-?Applicant)[^<]*</td>#is',
            '',
            $html
        );

        // 5. Remove empty <td> cells that held owner_2 signature/date values
        //    (now empty after placeholder substitution). Handles both width:25%
        //    (new template) and width:25px (legacy template).
        $html = preg_replace(
            '#<td[^>]*style="[^"]*width\s*:\s*25(?:%|px)[^"]*">\s*(?:&nbsp;|\s)*</td>#i',
            '',
            $html
        );

        // 6. Expand remaining primary signature cells from 25% to 50% width.
        //    After removing the owner_2 cells, the owner_1 signature and date cells
        //    should each take half the page width.
        $html = preg_replace(
            '#(<td[^>]*style="[^"]*?)width\s*:\s*25(?:%|px)#i',
            '$1width:50%',
            $html
        );

        return $html;
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
