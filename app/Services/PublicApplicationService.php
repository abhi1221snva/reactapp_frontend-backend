<?php

namespace App\Services;

use App\Services\TenantStorageService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * PublicApplicationService
 *
 * Handles public affiliate application form submissions and merchant portal access.
 * Operates WITHOUT authentication — all access is via affiliate_code or lead_token.
 */
class PublicApplicationService
{
    // Fields excluded from the public apply form (sensitive / internal)
    private const EXCLUDED_APPLY_KEYS = ['credit_score', 'unique_url', 'unique_token', 'signature_image', 'owner_2_ssn'];

    // Map crm_label.heading → section title
    private const SECTION_LABELS = [
        'business'     => 'Business Information',
        'owner'        => 'Owner Information',
        'second_owner' => 'Owner 2 Information',
        'other'        => 'Additional Information',
    ];

    // ─────────────────────────────────────────────────────────────────────────
    // AFFILIATE RESOLUTION
    // ─────────────────────────────────────────────────────────────────────────

    public function resolveAffiliate(string $code): array
    {
        $user = DB::table('users')
            ->where('affiliate_code', $code)
            ->where(function ($q) {
                $q->whereNull('is_deleted')->orWhere('is_deleted', 0);
            })
            ->select('id', 'first_name', 'last_name', 'email', 'mobile', 'parent_id', 'affiliate_code')
            ->first();

        if (!$user) {
            throw new \RuntimeException('Affiliate link not found or expired.', 404);
        }

        $clientId = $user->parent_id;

        $client = DB::connection("mysql_{$clientId}")
            ->table('crm_system_setting')
            ->orderBy('id')
            ->first();

        if (!$client) {
            $masterClient = DB::table('clients')->where('id', $clientId)->first();
            $client = (object) [
                'company_name'    => $masterClient->company_name ?? 'Our Company',
                'company_email'   => null,
                'company_phone'   => null,
                'company_address' => null,
                'city'            => null,
                'state'           => null,
                'zipcode'         => null,
                'logo'            => $masterClient->logo ?? null,
                'company_domain'  => null,
            ];
        }

        return [$user, $clientId, $client];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // COMPANY BRANDING
    // ─────────────────────────────────────────────────────────────────────────

    public function getCompanyBranding(object $client, int $clientId = 0): array
    {
        $logoRaw = $client->logo ?? null;
        $logoUrl = null;
        if ($logoRaw) {
            if (str_starts_with($logoRaw, 'http')) {
                $logoUrl = $logoRaw;
            } elseif ($clientId > 0) {
                // Logos stored in tenant storage → served via /public/tenant/{id}/logo
                $logoUrl = rtrim(env('APP_URL'), '/') . '/public/tenant/' . $clientId . '/logo';
            } else {
                // Legacy: public/logo/ folder
                $logoUrl = rtrim(env('APP_URL'), '/') . '/logo/' . $logoRaw;
            }
        }

        $websiteUrl = $client->company_domain ?? $client->website_url ?? null;
        $email      = $client->company_email  ?? $client->support_email ?? null;

        return [
            'company_name'    => $client->company_name    ?? 'Our Company',
            'company_phone'   => $client->company_phone   ?? '',
            'company_address' => $client->company_address ?? '',
            'city'            => $client->city             ?? '',
            'state'           => $client->state            ?? '',
            'zipcode'         => $client->zipcode          ?? '',
            'logo_url'        => $logoUrl,
            'website_url'     => $websiteUrl,
            'support_email'   => $email,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // FORM FIELD CONFIGURATION
    // ─────────────────────────────────────────────────────────────────────────

    public function getFormSections(int $clientId, bool $includeAll = false): array
    {
        $conn = "mysql_{$clientId}";

        if (!DB::connection($conn)->getSchemaBuilder()->hasTable('crm_label')) {
            return $this->defaultSections();
        }

        $labels = DB::connection($conn)
            ->table('crm_label')
            ->where('status', 1)
            ->where('is_deleted', 0)
            ->whereNotNull('label_title_url')
            ->where('label_title_url', '!=', '')
            ->orderBy('display_order')
            ->select('id', 'title', 'label_title_url', 'column_name', 'data_type', 'values', 'required', 'heading_type', 'placeholder')
            ->get();

        $grouped = [];
        foreach ($labels as $lbl) {
            $key = $lbl->label_title_url;
            if (!$includeAll && in_array($key, self::EXCLUDED_APPLY_KEYS, true)) continue;

            $heading = $lbl->heading_type ?: 'other';
            $section = self::SECTION_LABELS[$heading] ?? ucfirst($heading);

            $grouped[$section][] = [
                'key'         => $key,
                'label'       => $lbl->title,
                'type'        => $this->normalizeFieldType($lbl->data_type),
                'required'    => (bool) $lbl->required,
                'placeholder' => $lbl->placeholder ?? '',
                'options'     => $this->parseOptions($lbl->values),
                'column'      => $lbl->column_name,
            ];
        }

        $order    = array_values(self::SECTION_LABELS);
        $sections = [];
        foreach ($order as $title) {
            if (isset($grouped[$title])) {
                $sections[] = ['title' => $title, 'fields' => $grouped[$title]];
            }
        }
        foreach ($grouped as $title => $fields) {
            if (!in_array($title, $order, true)) {
                $sections[] = ['title' => $title, 'fields' => $fields];
            }
        }

        return $sections ?: $this->defaultSections();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // LEAD CREATION
    // ─────────────────────────────────────────────────────────────────────────

    public function createLead(int $clientId, object $affiliateUser, array $formData): array
    {
        $conn      = "mysql_{$clientId}";
        $leadToken = Str::random(32);
        $now       = now();

        // Extract signature before EAV storage (stored separately)
        $signatureData = $formData['signature_image'] ?? null;
        unset($formData['signature_image']);

        $columnMap = $this->buildColumnMap($conn);

        // ── crm_leads ─────────────────────────────────────────────────────────
        $leadId = DB::connection($conn)->table('crm_leads')->insertGetId([
            'lead_status'       => 'new_lead',
            'lead_type'         => 'warm',
            'assigned_to'       => $affiliateUser->id,
            'created_by'        => $affiliateUser->id,
            'affiliate_user_id' => $affiliateUser->id,
            'affiliate_code'    => $affiliateUser->affiliate_code,
            'lead_token'        => $leadToken,
            'unique_token'      => $leadToken,
            'created_at'        => $now,
            'updated_at'        => $now,
        ]);

        // ── EAV (crm_lead_values) ─────────────────────────────────────────────
        if (DB::connection($conn)->getSchemaBuilder()->hasTable('crm_lead_values')) {
            $eavRows = [];
            foreach ($formData as $fieldKey => $value) {
                if ($value === null || $value === '') continue;
                $eavRows[] = [
                    'lead_id'     => $leadId,
                    'field_key'   => $fieldKey,
                    'field_value' => (string) $value,
                    'created_at'  => $now,
                    'updated_at'  => $now,
                ];
            }
            if ($eavRows) {
                DB::connection($conn)->table('crm_lead_values')->insert($eavRows);
            }
        }

        // ── Legacy crm_lead_data ──────────────────────────────────────────────
        if (DB::connection($conn)->getSchemaBuilder()->hasTable('crm_lead_data')) {
            $legacyData = [
                'id'          => $leadId,
                'lead_status' => 'new_lead',
                'lead_type'   => 'warm',
                'assigned_to' => $affiliateUser->id,
                'created_by'  => $affiliateUser->id,
                'created_at'  => $now,
                'updated_at'  => $now,
            ];
            foreach ($formData as $fieldKey => $value) {
                if ($value === null || $value === '') continue;
                if (isset($columnMap['direct'][$fieldKey])) {
                    $legacyData[$columnMap['direct'][$fieldKey]] = $value;
                } elseif (isset($columnMap['mapped'][$fieldKey])) {
                    $legacyData[$columnMap['mapped'][$fieldKey]] = $value;
                }
            }
            try {
                DB::connection($conn)->table('crm_lead_data')->insert($legacyData);
            } catch (\Throwable $e) {
                \Log::warning("PublicApp: crm_lead_data insert failed for lead {$leadId}: " . $e->getMessage());
            }
        }

        // ── Signature ─────────────────────────────────────────────────────────
        $signatureUrl = null;
        if ($signatureData) {
            $signatureUrl = $this->storeSignature($clientId, $leadId, $signatureData, $conn);
        }

        // ── Activity log ──────────────────────────────────────────────────────
        $this->logActivity($conn, $leadId, $affiliateUser->id, 'affiliate_application', 'Lead created via affiliate application form.');

        // ── Merchant URL ──────────────────────────────────────────────────────
        $portalBase  = $this->resolvePortalBase($clientId);
        $merchantUrl = $portalBase . '/merchant/customer/app/index/' . $clientId . '/' . $leadId . '/' . $leadToken;

        // Persist the generated merchant URL on the lead record
        DB::connection($conn)->table('crm_leads')
            ->where('id', $leadId)
            ->update(['unique_url' => $merchantUrl]);

        \Log::info('[PublicApp createLead] merchant_url generated', [
            'client_id' => $clientId,
            'lead_id'   => $leadId,
            'domain'    => $portalBase,
            'url'       => $merchantUrl,
        ]);

        return [
            'lead_id'       => $leadId,
            'lead_token'    => $leadToken,
            'merchant_url'  => $merchantUrl,
            'signature_url' => $signatureUrl,
            'pdf_url'       => rtrim(env('APP_URL'), '/') . '/public/apply/' . $leadToken . '/pdf',
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // SIGNATURE STORAGE
    // ─────────────────────────────────────────────────────────────────────────

    public function storeSignature(int $clientId, int $leadId, string $base64Data, string $conn): ?string
    {
        try {
            $imgData = preg_replace('/^data:image\/\w+;base64,/', '', $base64Data);
            $decoded = base64_decode($imgData);
            if (!$decoded || strlen($decoded) < 50) return null;

            // Organized folder: storage/app/clients/client_{id}/leads/{leadId}/signatures/
            $subdir  = "leads/{$leadId}/signatures";
            $dir     = TenantStorageService::getPath($clientId, $subdir);
            if (!is_dir($dir)) {
                mkdir($dir, 0775, true);
            }

            $filename = 'signature_' . time() . '.png';
            file_put_contents($dir . DIRECTORY_SEPARATOR . $filename, $decoded);

            // Relative path stored in EAV (relative from client base)
            $relPath = "leads/{$leadId}/signatures/{$filename}";

            // Store path in crm_lead_data signature_image column
            try {
                DB::connection($conn)->table('crm_lead_data')
                    ->where('id', $leadId)
                    ->update(['signature_image' => $relPath, 'updated_at' => now()]);
            } catch (\Throwable $e) {}

            // Store in EAV
            if (DB::connection($conn)->getSchemaBuilder()->hasTable('crm_lead_values')) {
                DB::connection($conn)->table('crm_lead_values')->updateOrInsert(
                    ['lead_id' => $leadId, 'field_key' => 'signature_image'],
                    ['field_value' => $relPath, 'updated_at' => now(), 'created_at' => now()]
                );
            }

            // Return inline data URI (used immediately in success response)
            return 'data:image/png;base64,' . base64_encode($decoded);
        } catch (\Throwable $e) {
            \Log::warning("PublicApp: storeSignature failed for lead {$leadId}: " . $e->getMessage());
            return null;
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PDF / HTML GENERATION
    // ─────────────────────────────────────────────────────────────────────────

    public function generateApplicationHtml(int $clientId, object $lead, array $sections, array $branding): string
    {
        $leadData = $this->getMerchantLeadData($lead, $clientId);
        $fields   = $leadData['fields'];

        $sigPath   = $fields['signature_image'] ?? null;
        $sigInline = null;
        if ($sigPath) {
            // Try new TenantStorageService path (leads/{leadId}/signatures/...)
            $absPath = TenantStorageService::getPath($clientId, $sigPath);
            if (!file_exists($absPath)) {
                // Backward compat: old public disk path (crm_documents/{clientId}/{leadId}/...)
                $absPath = storage_path('app/public/' . $sigPath);
            }
            if (file_exists($absPath)) {
                $sigInline = 'data:image/png;base64,' . base64_encode(file_get_contents($absPath));
            }
        }
        $logoUrl = $branding['logo_url'] ?? null;
        $company = htmlspecialchars($branding['company_name'] ?? 'Funding Application');
        $email   = htmlspecialchars($branding['support_email'] ?? '');
        $phone   = htmlspecialchars($branding['company_phone'] ?? '');
        $date    = date('F j, Y');

        $logoHtml = $logoUrl
            ? "<img src='" . htmlspecialchars($logoUrl) . "' class='logo' alt='Logo' />"
            : "<div class='logo-placeholder'>" . substr($company, 0, 2) . "</div>";

        $sectionsHtml = '';
        foreach ($sections as $section) {
            $title = htmlspecialchars($section['title'] ?? '');
            $sectionsHtml .= "<div class='section'><h3 class='section-title'>{$title}</h3><div class='fields'>";
            foreach ($section['fields'] as $field) {
                $key   = $field['key'] ?? '';
                $label = htmlspecialchars($field['label'] ?? '');
                $raw   = $fields[$key] ?? '';
                // Mask SSN
                if (in_array($key, ['ssn', 'owner_ssn'], true) && strlen($raw) >= 4) {
                    $raw = '***-**-' . substr($raw, -4);
                }
                $value = htmlspecialchars($raw);
                $sectionsHtml .= "<div class='field'><span class='label'>{$label}</span><span class='value'>" . ($value ?: '—') . "</span></div>";
            }
            $sectionsHtml .= "</div></div>";
        }

        $sigHtml = $sigInline
            ? "<div class='section sig-section'><h3 class='section-title'>Digital Signature</h3><img src='" . $sigInline . "' style='max-height:100px;border:1px solid #e2e8f0;padding:12px;border-radius:8px;background:#fff;' /></div>"
            : '';

        $docsHtml = '';
        if (!empty($leadData['documents'])) {
            $docsHtml = "<div class='section'><h3 class='section-title'>Uploaded Documents</h3><ul class='doc-list'>";
            foreach ($leadData['documents'] as $doc) {
                $name = htmlspecialchars($doc['filename'] ?? '');
                $type = htmlspecialchars($doc['doc_type'] ?? '');
                $docsHtml .= "<li>{$name} <span class='doc-type'>({$type})</span></li>";
            }
            $docsHtml .= "</ul></div>";
        }

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Funding Application — {$company}</title>
<style>
  * { box-sizing: border-box; margin: 0; padding: 0; }
  body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Arial, sans-serif; color: #1e293b; background: #f8fafc; padding: 0; }
  .page { max-width: 900px; margin: 0 auto; background: #fff; min-height: 100vh; }
  .header { background: linear-gradient(135deg, #0f172a, #1e293b); color: #fff; padding: 32px 40px; display: flex; align-items: center; gap: 20px; }
  .logo { max-height: 56px; max-width: 140px; object-fit: contain; border-radius: 6px; }
  .logo-placeholder { width: 56px; height: 56px; background: #4f46e5; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 22px; font-weight: 800; color: #fff; }
  .company-name { font-size: 22px; font-weight: 700; color: #fff; }
  .company-sub  { font-size: 13px; color: #94a3b8; margin-top: 4px; }
  .badge { display: inline-block; background: #4f46e5; color: #fff; font-size: 11px; font-weight: 600; padding: 4px 10px; border-radius: 20px; margin-top: 8px; }
  .body { padding: 32px 40px; }
  .section { margin-bottom: 28px; page-break-inside: avoid; }
  .section-title { font-size: 13px; font-weight: 700; color: #4f46e5; text-transform: uppercase; letter-spacing: 1px; padding-bottom: 8px; border-bottom: 2px solid #e0e7ff; margin-bottom: 14px; }
  .fields { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
  .field { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 10px 14px; }
  .label { font-size: 10px; font-weight: 600; color: #94a3b8; text-transform: uppercase; letter-spacing: 0.6px; display: block; margin-bottom: 4px; }
  .value { font-size: 14px; font-weight: 500; color: #1e293b; }
  .sig-section { margin-top: 24px; }
  .doc-list { list-style: none; display: flex; flex-direction: column; gap: 6px; }
  .doc-list li { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 8px 14px; font-size: 13px; }
  .doc-type { color: #64748b; font-size: 11px; }
  .footer { background: #f8fafc; border-top: 1px solid #e2e8f0; padding: 20px 40px; font-size: 12px; color: #94a3b8; display: flex; justify-content: space-between; }
  .contact { display: flex; gap: 24px; }
  @media print {
    body { background: #fff; }
    .page { box-shadow: none; }
    @page { margin: 0.5in; }
  }
</style>
</head>
<body>
<div class="page">
  <div class="header">
    {$logoHtml}
    <div>
      <div class="company-name">{$company}</div>
      <div class="company-sub">Funding Application — {$date}</div>
      <span class="badge">CONFIDENTIAL</span>
    </div>
  </div>
  <div class="body">
    {$sectionsHtml}
    {$sigHtml}
    {$docsHtml}
  </div>
  <div class="footer">
    <span>Application submitted on {$date}</span>
    <div class="contact">
      {$email}<span>{$phone}</span>
    </div>
  </div>
</div>
<script>window.addEventListener('load', () => setTimeout(() => window.print(), 300))</script>
</body>
</html>
HTML;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // MERCHANT PORTAL
    // ─────────────────────────────────────────────────────────────────────────

    public function resolveLeadToken(string $token): array
    {
        $clients = DB::table('clients')->where('is_deleted', 0)->pluck('id');

        foreach ($clients as $clientId) {
            $conn = "mysql_{$clientId}";
            try {
                if (!DB::connection($conn)->getSchemaBuilder()->hasTable('crm_leads')) continue;
                $lead = DB::connection($conn)->table('crm_leads')
                    ->where(function ($q) use ($token) {
                        $q->where('lead_token', $token)
                          ->orWhere('unique_token', $token);
                    })
                    ->whereNull('deleted_at')
                    ->first();
                if ($lead) {
                    $client = DB::connection($conn)->table('crm_system_setting')->orderBy('id')->first();
                    if (!$client) {
                        $mc = DB::table('clients')->where('id', $clientId)->first();
                        $client = (object) [
                            'company_name' => $mc->company_name ?? '',
                            'company_email' => null, 'company_phone' => null,
                            'company_address' => null, 'city' => null, 'state' => null,
                            'zipcode' => null, 'logo' => $mc->logo ?? null, 'company_domain' => null,
                        ];
                    }
                    return [$lead, $clientId, $client];
                }
            } catch (\Throwable $e) {
                continue;
            }
        }

        throw new \RuntimeException('Application not found or link expired.', 404);
    }

    public function getMerchantLeadData(object $lead, int $clientId): array
    {
        $conn   = "mysql_{$clientId}";
        $leadId = $lead->id;

        $eavData = [];
        if (DB::connection($conn)->getSchemaBuilder()->hasTable('crm_lead_values')) {
            $eavData = DB::connection($conn)
                ->table('crm_lead_values')
                ->where('lead_id', $leadId)
                ->pluck('field_value', 'field_key')
                ->toArray();
        }

        $legacyData = [];
        if (DB::connection($conn)->getSchemaBuilder()->hasTable('crm_lead_data')) {
            $row = DB::connection($conn)->table('crm_lead_data')->where('id', $leadId)->first();
            if ($row) {
                $columnMap = $this->buildColumnMap($conn);
                foreach ($columnMap['mapped'] as $fieldKey => $col) {
                    $val = ((array) $row)[$col] ?? null;
                    if ($val !== null && $val !== '') $legacyData[$fieldKey] = $val;
                }
                foreach (['first_name','last_name','email','phone_number','dob','city','state','country','address','company_name','signature_image'] as $col) {
                    if (!empty(((array) $row)[$col])) $legacyData[$col] = ((array) $row)[$col];
                }
            }
        }

        $merged = array_merge($legacyData, $eavData);

        // Build a backend-served signature URL so the frontend never needs to
        // construct a path into the non-public app storage directory.
        $hasSig      = !empty($merged['signature_image']);
        $signatureUrl = $hasSig
            ? rtrim(env('APP_URL'), '/') . '/public/lead/' . $lead->lead_token . '/signature'
            : null;

        return [
            'id'             => $lead->id,
            'lead_status'    => $lead->lead_status,
            'lead_type'      => $lead->lead_type,
            'lead_token'     => $lead->lead_token,
            'affiliate_code' => $lead->affiliate_code ?? null,
            'created_at'     => $lead->created_at,
            'fields'         => $merged,
            'signature_url'  => $signatureUrl,
            'documents'      => $this->getDocuments($clientId, $leadId),
        ];
    }

    public function updateMerchantLead(object $lead, int $clientId, array $formData): void
    {
        $conn   = "mysql_{$clientId}";
        $leadId = $lead->id;
        $now    = now();

        // ── 1. Load current values for diff (legacy + EAV, same as display) ──
        // Must mirror getMerchantLeadData() merge so we compare what the
        // merchant actually sees, not just what's in EAV (which may be empty
        // for leads that were created before the EAV migration).
        $legacyCurrent = [];
        if (DB::connection($conn)->getSchemaBuilder()->hasTable('crm_lead_data')) {
            $legacyRow = DB::connection($conn)->table('crm_lead_data')->where('id', $leadId)->first();
            if ($legacyRow) {
                $columnMap = $this->buildColumnMap($conn);
                foreach ($columnMap['mapped'] as $fieldKey => $col) {
                    $val = ((array) $legacyRow)[$col] ?? null;
                    if ($val !== null && $val !== '') $legacyCurrent[$fieldKey] = $val;
                }
                foreach (['first_name','last_name','email','phone_number','dob','city','state','country','address','company_name','signature_image'] as $col) {
                    if (!empty(((array) $legacyRow)[$col])) $legacyCurrent[$col] = ((array) $legacyRow)[$col];
                }
            }
        }

        $eavCurrent = [];
        if (DB::connection($conn)->getSchemaBuilder()->hasTable('crm_lead_values')) {
            $eavCurrent = DB::connection($conn)
                ->table('crm_lead_values')
                ->where('lead_id', $leadId)
                ->pluck('field_value', 'field_key')
                ->toArray();
        }

        // EAV takes priority over legacy (same merge order as getMerchantLeadData)
        $currentValues = array_merge($legacyCurrent, $eavCurrent);

        // ── 2. Detect actual changes ──────────────────────────────────────────
        $changes = [];
        foreach ($formData as $key => $newVal) {
            $newVal = ($newVal === '') ? null : $newVal;
            $oldVal = $currentValues[$key] ?? null;
            if ((string) $oldVal !== (string) $newVal) {
                $changes[$key] = ['old' => $oldVal, 'new' => $newVal];
            }
        }

        if (empty($changes)) {
            return; // nothing actually changed — skip all writes
        }

        // ── 3. Write EAV updates ──────────────────────────────────────────────
        if (DB::connection($conn)->getSchemaBuilder()->hasTable('crm_lead_values')) {
            foreach ($changes as $key => $diff) {
                DB::connection($conn)->table('crm_lead_values')->updateOrInsert(
                    ['lead_id' => $leadId, 'field_key' => $key],
                    ['field_value' => $diff['new'], 'updated_at' => $now, 'created_at' => $now]
                );
            }
        }

        // ── 4. Mirror to legacy crm_lead_data if it exists ───────────────────
        if (DB::connection($conn)->getSchemaBuilder()->hasTable('crm_lead_data')) {
            $columnMap  = $this->buildColumnMap($conn);
            $updateData = ['updated_at' => $now];
            foreach ($changes as $fieldKey => $diff) {
                if (isset($columnMap['direct'][$fieldKey])) {
                    $updateData[$columnMap['direct'][$fieldKey]] = $diff['new'];
                } elseif (isset($columnMap['mapped'][$fieldKey])) {
                    $updateData[$columnMap['mapped'][$fieldKey]] = $diff['new'];
                }
            }
            try {
                DB::connection($conn)->table('crm_lead_data')->where('id', $leadId)->update($updateData);
            } catch (\Throwable $e) {
                \Log::warning("Merchant update crm_lead_data: " . $e->getMessage());
            }
        }

        // ── 5. Write per-field activity entries to crm_lead_activity ─────────
        if (DB::connection($conn)->getSchemaBuilder()->hasTable('crm_lead_activity')) {
            foreach ($changes as $field => $diff) {
                $label   = ucwords(str_replace('_', ' ', $field));
                $oldDisp = $diff['old'] ?? '(empty)';
                $newDisp = $diff['new'] ?? '(empty)';
                try {
                    DB::connection($conn)->table('crm_lead_activity')->insert([
                        'lead_id'       => $leadId,
                        'user_id'       => null,
                        'activity_type' => 'system',
                        'subject'       => "Merchant updated {$label}: \"{$oldDisp}\" → \"{$newDisp}\"",
                        'body'          => null,
                        'meta'          => json_encode([
                            'field'     => $field,
                            'old_value' => $diff['old'],
                            'new_value' => $diff['new'],
                            'source'    => 'merchant_portal',
                        ]),
                        'source_type'   => 'api',
                        'is_pinned'     => 0,
                        'created_at'    => $now,
                        'updated_at'    => $now,
                    ]);
                } catch (\Throwable $e) {
                    \Log::warning("Merchant activity insert failed for field {$field}: " . $e->getMessage());
                }
            }
        }
    }

    public function storeDocument(object $lead, int $clientId, $file, string $docType): array
    {
        $conn = "mysql_{$clientId}";
        $now  = now();

        // Store on public disk (consistent with CrmDocumentController)
        $storagePath = $file->store("crm_documents/client_{$clientId}/lead_{$lead->id}", 'public');
        $filename    = basename($storagePath);
        $fileUrl     = rtrim(env('APP_URL'), '/') . '/storage/' . $storagePath;

        $docId = DB::connection($conn)->table('crm_documents')->insertGetId([
            'lead_id'       => $lead->id,
            'document_name' => $file->getClientOriginalName(),
            'document_type' => $docType,
            'file_name'     => $file->getClientOriginalName(),
            'file_path'     => $fileUrl,
            'uploaded_by'   => 0,
            'file_size'     => $file->getSize(),
            'created_at'    => $now,
            'updated_at'    => $now,
        ]);

        return [
            'filename' => $filename,
            'path'     => $storagePath,
            'url'      => $fileUrl,
        ];
    }

    /**
     * Serve the signature image for a lead (public, no auth).
     * Returns [absPath, mimeType] or throws RuntimeException.
     */
    public function serveLeadSignature(int $clientId, int $leadId): array
    {
        $conn    = "mysql_{$clientId}";
        $sigPath = null;

        if (DB::connection($conn)->getSchemaBuilder()->hasTable('crm_lead_values')) {
            $sigPath = DB::connection($conn)->table('crm_lead_values')
                ->where('lead_id', $leadId)
                ->where('field_key', 'signature_image')
                ->value('field_value');
        }

        // Fallback: try legacy crm_lead_data column
        if (!$sigPath && DB::connection($conn)->getSchemaBuilder()->hasTable('crm_lead_data')) {
            $sigPath = DB::connection($conn)->table('crm_lead_data')
                ->where('id', $leadId)
                ->value('signature_image');
        }

        if (!$sigPath) {
            throw new \RuntimeException('Signature not found.', 404);
        }

        $absPath = TenantStorageService::getPath($clientId, $sigPath);
        if (!file_exists($absPath)) {
            $absPath = storage_path('app/public/' . $sigPath);
        }

        if (!file_exists($absPath)) {
            throw new \RuntimeException('Signature file not found.', 404);
        }

        return [$absPath, 'image/png'];
    }

    /**
     * Serve a lead document by token + document ID (public, no auth).
     * Returns [absPath, mimeType, filename] or throws RuntimeException.
     */
    public function serveLeadDocument(string $token, int $docId): array
    {
        [$lead, $clientId] = $this->resolveLeadToken($token);
        $conn              = "mysql_{$clientId}";

        $doc = DB::connection($conn)->getSchemaBuilder()->hasTable('crm_documents')
            ? DB::connection($conn)->table('crm_documents')
                ->where('id', $docId)->where('lead_id', $lead->id)->whereNull('deleted_at')->first()
            : null;

        if (!$doc) {
            throw new \RuntimeException('Document not found.', 404);
        }

        $filePath = $doc->file_path ?? '';
        $filename = $doc->file_name ?? basename($filePath);

        // file_path is a full public URL — signal controller to redirect
        if (str_starts_with($filePath, 'http')) {
            return [$filePath, 'redirect', $filename];
        }

        // Legacy: relative path on disk
        $absPath = TenantStorageService::getPath($clientId, $filePath);
        if (!file_exists($absPath)) {
            $absPath = storage_path('app/public/' . $filePath);
        }

        if (!file_exists($absPath)) {
            throw new \RuntimeException('Document file not found.', 404);
        }

        $mime = mime_content_type($absPath) ?: 'application/octet-stream';
        return [$absPath, $mime, $filename];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // AFFILIATE CODE MANAGEMENT
    // ─────────────────────────────────────────────────────────────────────────

    public function generateAffiliateCode(object $user): string
    {
        $base = strtolower(
            preg_replace('/[^a-z0-9]/i', '', $user->first_name) .
            substr(preg_replace('/[^a-z0-9]/i', '', $user->last_name), 0, 4)
        );
        $base  = $base ?: 'agent';
        $code  = $base . rand(100, 9999);
        $tries = 0;
        while (DB::table('users')->where('affiliate_code', $code)->exists() && $tries < 20) {
            $code = $base . rand(1000, 99999);
            $tries++;
        }
        return $code;
    }

    public function buildAffiliateUrl(int $clientId, string $affiliateCode): string
    {
        return $this->resolvePortalBase($clientId) . '/apply/' . $affiliateCode;
    }

    /**
     * Resolve the portal/frontend base URL for a client from crm_system_setting.company_domain.
     * Falls back to APP_FRONTEND_URL / APP_URL if not configured.
     * Logs the resolved domain and emits a warning when falling back.
     * Always returns a URL with NO trailing slash.
     */
    private function resolvePortalBase(int $clientId): string
    {
        $domain = DB::connection("mysql_{$clientId}")
            ->table('crm_system_setting')
            ->orderBy('id')
            ->value('company_domain');

        if (empty($domain)) {
            $fallback = env('APP_FRONTEND_URL', env('APP_URL', ''));
            \Log::warning("[PublicApp] company_domain not configured for client {$clientId} — falling back to: {$fallback}");
            return rtrim($fallback, '/');
        }

        \Log::info("[PublicApp] resolvePortalBase client={$clientId} domain={$domain}");
        return rtrim($domain, '/');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // HELPERS
    // ─────────────────────────────────────────────────────────────────────────

    private function buildColumnMap(string $conn): array
    {
        $direct = [
            'first_name'    => 'first_name',
            'last_name'     => 'last_name',
            'email'         => 'email',
            'phone_number'  => 'phone_number',
            'mobile'        => 'phone_number',
            'dob'           => 'dob',
            'city'          => 'city',
            'state'         => 'state',
            'country'       => 'country',
            'address'       => 'address',
            'company_name'  => 'company_name',
            'business_name' => 'company_name',
        ];

        $mapped = [];
        if (DB::connection($conn)->getSchemaBuilder()->hasTable('crm_label')) {
            $rows   = DB::connection($conn)->table('crm_label')
                ->whereNotNull('label_title_url')
                ->where('label_title_url', '!=', '')
                ->pluck('column_name', 'label_title_url');
            $mapped = $rows->toArray();
        }

        return compact('direct', 'mapped');
    }

    private function normalizeFieldType(string $type): string
    {
        return [
            'phone_number' => 'tel',
            'number'       => 'number',
            'date'         => 'date',
            'email'        => 'email',
            'text'         => 'text',
            'select'       => 'select',
            'select_state' => 'select',
            'textarea'     => 'textarea',
            'checkbox'     => 'checkbox',
            'ssn'          => 'ssn',
        ][$type] ?? 'text';
    }

    private function parseOptions(?string $values): array
    {
        if (empty($values)) return [];
        $decoded = json_decode($values, true);
        if (is_array($decoded)) return $decoded;
        return array_map('trim', explode(',', $values));
    }

    private function getDocuments(int $clientId, int $leadId): array
    {
        $conn = "mysql_{$clientId}";
        if (!DB::connection($conn)->getSchemaBuilder()->hasTable('crm_documents')) return [];

        return DB::connection($conn)->table('crm_documents')
            ->where('lead_id', $leadId)
            ->whereNull('deleted_at')
            ->orderBy('id', 'desc')
            ->get()
            ->map(function ($d) {
                return [
                    'id'       => $d->id,
                    'filename' => $d->file_name,
                    'doc_type' => $d->document_type,
                    'url'      => $d->file_path,  // already a full public URL
                    'uploaded' => $d->created_at,
                ];
            })
            ->toArray();
    }

    private function logActivity(string $conn, int $leadId, int $userId, string $type, string $note): void
    {
        try {
            if (!DB::connection($conn)->getSchemaBuilder()->hasTable('crm_lead_activity')) return;
            DB::connection($conn)->table('crm_lead_activity')->insert([
                'lead_id'       => $leadId,
                'user_id'       => $userId ?: null,
                'activity_type' => $type,   // was 'activity' — wrong column name, fixed
                'subject'       => $note,   // was 'description' — wrong column name, fixed
                'body'          => null,
                'meta'          => null,
                'source_type'   => 'api',
                'is_pinned'     => 0,
                'created_at'    => now(),
                'updated_at'    => now(),
            ]);
        } catch (\Throwable $e) { /* non-fatal */ }
    }

    private function defaultSections(): array
    {
        $usStates = [
            'Alabama','Alaska','Arizona','Arkansas','California','Colorado','Connecticut',
            'Delaware','Florida','Georgia','Hawaii','Idaho','Illinois','Indiana','Iowa',
            'Kansas','Kentucky','Louisiana','Maine','Maryland','Massachusetts','Michigan',
            'Minnesota','Mississippi','Missouri','Montana','Nebraska','Nevada','New Hampshire',
            'New Jersey','New Mexico','New York','North Carolina','North Dakota','Ohio',
            'Oklahoma','Oregon','Pennsylvania','Rhode Island','South Carolina','South Dakota',
            'Tennessee','Texas','Utah','Vermont','Virginia','Washington','West Virginia',
            'Wisconsin','Wyoming',
        ];

        return [
            [
                'title'  => 'Business Information',
                'fields' => [
                    ['key' => 'business_name',    'label' => 'Business Legal Name', 'type' => 'text',   'required' => true,  'placeholder' => 'Enter business name', 'options' => []],
                    ['key' => 'dba',              'label' => 'DBA (if different)',  'type' => 'text',   'required' => false, 'placeholder' => 'Doing business as',   'options' => []],
                    ['key' => 'business_address', 'label' => 'Business Address',    'type' => 'text',   'required' => true,  'placeholder' => 'Street address',      'options' => []],
                    ['key' => 'business_city',    'label' => 'City',                'type' => 'text',   'required' => true,  'placeholder' => 'City',                'options' => []],
                    ['key' => 'business_state',   'label' => 'State',               'type' => 'select', 'required' => true,  'placeholder' => '',                    'options' => $usStates],
                    ['key' => 'business_zip',     'label' => 'Zip Code',            'type' => 'text',   'required' => true,  'placeholder' => '12345',               'options' => []],
                    ['key' => 'business_phone',   'label' => 'Business Phone',      'type' => 'tel',    'required' => true,  'placeholder' => '(555) 000-0000',      'options' => []],
                ],
            ],
            [
                'title'  => 'Owner Information',
                'fields' => [
                    ['key' => 'first_name', 'label' => 'First Name',    'type' => 'text',  'required' => true,  'placeholder' => 'First name',    'options' => []],
                    ['key' => 'last_name',  'label' => 'Last Name',     'type' => 'text',  'required' => true,  'placeholder' => 'Last name',     'options' => []],
                    ['key' => 'email',      'label' => 'Email Address', 'type' => 'email', 'required' => true,  'placeholder' => 'you@email.com', 'options' => []],
                    ['key' => 'mobile',     'label' => 'Phone Number',  'type' => 'tel',   'required' => true,  'placeholder' => '(555) 000-0000','options' => []],
                    ['key' => 'dob',        'label' => 'Date of Birth', 'type' => 'date',  'required' => true,  'placeholder' => '',              'options' => []],
                    ['key' => 'ssn',        'label' => 'SSN',           'type' => 'ssn',   'required' => true,  'placeholder' => '***-**-****',   'options' => []],
                ],
            ],
            [
                'title'  => 'Business Details',
                'fields' => [
                    ['key' => 'years_in_business', 'label' => 'Years in Business',   'type' => 'number', 'required' => true,  'placeholder' => 'e.g. 3', 'options' => []],
                    ['key' => 'monthly_revenue',   'label' => 'Monthly Revenue ($)', 'type' => 'number', 'required' => true,  'placeholder' => '0.00',   'options' => []],
                    ['key' => 'industry',          'label' => 'Industry',            'type' => 'select', 'required' => true,  'placeholder' => '',        'options' => ['Restaurant/Food Service','Retail','Healthcare','Construction','Transportation','Auto Dealer','Beauty/Salon','Fitness/Gym','Real Estate','Technology','Manufacturing','Professional Services','E-Commerce','Other']],
                    ['key' => 'business_type',     'label' => 'Business Type',       'type' => 'select', 'required' => true,  'placeholder' => '',        'options' => ['LLC','Corporation','Sole Proprietor','Partnership','S-Corp','C-Corp','Non-Profit','Other']],
                ],
            ],
            [
                'title'  => 'Funding Request',
                'fields' => [
                    ['key' => 'amount_requested', 'label' => 'Requested Amount ($)', 'type' => 'number',   'required' => true,  'placeholder' => '0.00',              'options' => []],
                    ['key' => 'use_of_funds',     'label' => 'Purpose of Funds',     'type' => 'textarea', 'required' => true,  'placeholder' => 'Describe how you plan to use the funds...', 'options' => []],
                    ['key' => 'existing_loans',   'label' => 'Existing Business Loans?', 'type' => 'select', 'required' => false,'placeholder' => '',               'options' => ['No','Yes']],
                ],
            ],
            [
                'title'  => 'Bank Information',
                'fields' => [
                    ['key' => 'bank_name',    'label' => 'Bank Name',    'type' => 'text',   'required' => true, 'placeholder' => 'Name of your bank', 'options' => []],
                    ['key' => 'account_type', 'label' => 'Account Type', 'type' => 'select', 'required' => true, 'placeholder' => '',                  'options' => ['Checking','Savings','Business Checking','Business Savings']],
                ],
            ],
        ];
    }
}
