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

    // Map crm_label.heading_type → section title (used by legacy getSectionsFromLegacy)
    private const SECTION_LABELS = [
        'business'     => 'Business Information',
        'owner'        => 'Owner Information',
        'second_owner' => 'Owner 2 Information',
        'other'        => 'Additional Information',
    ];

    // Map crm_labels.section key → display label (mirrors SECTION_MAP in CrmLeadFields.tsx)
    private const SECTION_LABEL_MAP = [
        // Current structured sections
        'owner'        => 'Owner Information',
        'business'     => 'Business Information',
        'funding'      => 'Funding Information',
        'contact'      => 'Contact Information',
        'financial'    => 'Financial Information',
        'documents'    => 'Documents / Verification',
        'custom'       => 'Custom Fields',
        // Legacy section keys (backward compatibility)
        'second_owner' => 'Owner 2 Information',
        'general'      => 'General Information',
        'other'        => 'Additional Information',
        'address'      => 'Address Information',
        'banking'      => 'Bank Information',
        'bank'         => 'Bank Information',
        'details'      => 'Business Details',
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

        // ── Priority 1: New EAV crm_labels table (source of truth from CRM lead-fields) ──
        if (DB::connection($conn)->getSchemaBuilder()->hasTable('crm_labels')) {
            $sections = $this->getSectionsFromEav($conn, $includeAll);
            if (!empty($sections)) return $sections;
        }

        // ── Priority 2: Legacy crm_label table ────────────────────────────────
        if (DB::connection($conn)->getSchemaBuilder()->hasTable('crm_label')) {
            $sections = $this->getSectionsFromLegacy($conn, $includeAll);
            if (!empty($sections)) return $sections;
        }

        // ── Fallback: hardcoded defaults ──────────────────────────────────────
        return $this->defaultSections();
    }

    /**
     * Build sections from crm_labels (EAV, source of truth for CRM lead-fields page).
     */
    private function getSectionsFromEav(string $conn, bool $includeAll): array
    {
        $labels = DB::connection($conn)
            ->table('crm_labels')
            ->where('status', 1)
            ->orderBy('display_order')
            ->select('label_name', 'field_key', 'field_type', 'section', 'options', 'required', 'placeholder', 'display_order')
            ->get();

        // Track section insertion order by the first field's display_order
        $sectionFirstOrder = [];
        $grouped           = [];

        foreach ($labels as $lbl) {
            $key = $lbl->field_key;
            if (!$includeAll && in_array($key, self::EXCLUDED_APPLY_KEYS, true)) continue;

            $rawSection = (isset($lbl->section) && trim((string) $lbl->section) !== '')
                ? strtolower(trim((string) $lbl->section))
                : 'general';
            // Resolve display label: map known keys, fall back to title-casing the raw value
            $section = self::SECTION_LABEL_MAP[$rawSection]
                ?? ucwords(str_replace('_', ' ', $rawSection));

            if (!isset($sectionFirstOrder[$section])) {
                $sectionFirstOrder[$section] = $lbl->display_order ?? 9999;
            }

            $grouped[$section][] = [
                'key'         => $key,
                'label'       => $lbl->label_name,
                'type'        => $this->normalizeEavFieldType($lbl->field_type),
                'required'    => (bool) $lbl->required,
                'placeholder' => $lbl->placeholder ?? '',
                'options'     => $this->parseJsonOptions($lbl->options),
            ];
        }

        if (empty($grouped)) return [];

        // Sort sections by the display_order of their first field
        asort($sectionFirstOrder);

        $sections = [];
        foreach (array_keys($sectionFirstOrder) as $title) {
            if (isset($grouped[$title])) {
                $sections[] = ['title' => $title, 'fields' => $grouped[$title]];
            }
        }

        return $sections;
    }

    /**
     * Build sections from legacy crm_label table (fallback).
     */
    private function getSectionsFromLegacy(string $conn, bool $includeAll): array
    {
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

        return $sections;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // LEAD CREATION
    // ─────────────────────────────────────────────────────────────────────────

    public function createLead(int $clientId, object $affiliateUser, array $formData): array
    {
        $conn      = "mysql_{$clientId}";
        $leadToken = Str::random(32);
        $now       = now();

        // Extract both signatures before EAV storage (stored separately, not as EAV fields)
        $signatureData  = $formData['signature_image'] ?? null;
        $signatureData2 = $formData['owner_2_signature_image'] ?? null;
        unset($formData['signature_image'], $formData['owner_2_signature_image']);

        // Normalize phone field — ensure 'mobile' alias exists for legacy mapping
        if (empty($formData['mobile'])) {
            foreach (['phone_number', 'phone', 'cell_phone', 'telephone', 'cell'] as $alt) {
                if (!empty($formData[$alt])) {
                    $formData['mobile'] = $formData[$alt];
                    break;
                }
            }
        }

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

        // ── Signatures ────────────────────────────────────────────────────────
        $signatureUrl = null;
        if ($signatureData) {
            $signatureUrl = $this->storeSignature($clientId, $leadId, $signatureData, $conn, 'signature_image');
        }
        if ($signatureData2) {
            $this->storeSignature($clientId, $leadId, $signatureData2, $conn, 'owner_2_signature_image');
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

        // ── Register in master index for instant future lookups ──────────────
        try {
            DB::table('lead_token_map')->insertOrIgnore([
                'lead_token' => $leadToken,
                'client_id'  => $clientId,
                'lead_id'    => $leadId,
                'created_at' => $now,
            ]);
        } catch (\Throwable $e) {
            \Log::warning("[PublicApp createLead] lead_token_map insert failed: " . $e->getMessage());
        }

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

    /**
     * Store a base64 signature image for a lead.
     *
     * @param  string $fieldKey  'signature_image' (Sig 1) or 'owner_2_signature_image' (Sig 2)
     * @return string|null       Inline data URI on success, null on failure
     */
    public function storeSignature(int $clientId, int $leadId, string $base64Data, string $conn, string $fieldKey = 'signature_image'): ?string
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

            // Use field key prefix in filename to keep both signatures distinct
            $prefix   = ($fieldKey === 'owner_2_signature_image') ? 'signature2_' : 'signature_';
            $filename = $prefix . time() . '.png';
            file_put_contents($dir . DIRECTORY_SEPARATOR . $filename, $decoded);

            // Relative path stored in EAV (relative from client base)
            $relPath = "leads/{$leadId}/signatures/{$filename}";

            // Mirror to legacy crm_lead_data column (same field key = column name)
            try {
                DB::connection($conn)->table('crm_lead_data')
                    ->where('id', $leadId)
                    ->update([$fieldKey => $relPath, 'updated_at' => now()]);
            } catch (\Throwable $e) {}

            // Store in EAV
            if (DB::connection($conn)->getSchemaBuilder()->hasTable('crm_lead_values')) {
                DB::connection($conn)->table('crm_lead_values')->updateOrInsert(
                    ['lead_id' => $leadId, 'field_key' => $fieldKey],
                    ['field_value' => $relPath, 'updated_at' => now(), 'created_at' => now()]
                );
            }

            // Return inline data URI (used immediately in success response)
            return 'data:image/png;base64,' . base64_encode($decoded);
        } catch (\Throwable $e) {
            \Log::warning("PublicApp: storeSignature({$fieldKey}) failed for lead {$leadId}: " . $e->getMessage());
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

        // Resolve Signature 1 (Applicant)
        $sigInline = null;
        $sigPath   = $fields['signature_image'] ?? null;
        if ($sigPath) {
            $absPath = TenantStorageService::getPath($clientId, $sigPath);
            if (!file_exists($absPath)) $absPath = storage_path('app/public/' . $sigPath);
            if (file_exists($absPath)) $sigInline = 'data:image/png;base64,' . base64_encode(file_get_contents($absPath));
        }

        // Resolve Signature 2 (Co-Applicant)
        $sig2Inline = null;
        $sig2Path   = $fields['owner_2_signature_image'] ?? null;
        if ($sig2Path) {
            $abs2Path = TenantStorageService::getPath($clientId, $sig2Path);
            if (!file_exists($abs2Path)) $abs2Path = storage_path('app/public/' . $sig2Path);
            if (file_exists($abs2Path)) $sig2Inline = 'data:image/png;base64,' . base64_encode(file_get_contents($abs2Path));
        }
        $logoUrl = $branding['logo_url'] ?? null;
        $company = htmlspecialchars($branding['company_name'] ?? 'Funding Application');
        $email   = htmlspecialchars($branding['support_email'] ?? '');
        $phone   = htmlspecialchars($branding['company_phone'] ?? '');
        $date    = date('F j, Y');

        $logoHtml = $logoUrl
            ? "<img src='" . htmlspecialchars($logoUrl) . "' class='logo' alt='Logo' />"
            : "<div class='logo-placeholder'>" . substr($company, 0, 2) . "</div>";

        // Build sections using HTML tables (dompdf does NOT support CSS Grid/Flexbox reliably)
        $sectionsHtml = '';
        foreach ($sections as $section) {
            $title      = htmlspecialchars($section['title'] ?? '');
            $fieldCells = [];
            foreach ($section['fields'] as $field) {
                $key   = $field['key'] ?? '';
                $label = htmlspecialchars($field['label'] ?? '');
                $raw   = $fields[$key] ?? '';
                if (in_array($key, ['ssn', 'owner_ssn'], true) && strlen($raw) >= 4) {
                    $raw = '***-**-' . substr($raw, -4);
                }
                $value        = htmlspecialchars($raw);
                $fieldCells[] = "<td class='field'><span class='lbl'>{$label}</span><span class='val'>" . ($value ?: '&mdash;') . "</span></td>";
            }
            // Pad last row to 3 columns
            while (count($fieldCells) % 3 !== 0) {
                $fieldCells[] = "<td class='field empty'></td>";
            }
            $rows = '';
            foreach (array_chunk($fieldCells, 3) as $row) {
                $rows .= '<tr>' . implode('', $row) . '</tr>';
            }
            $sectionsHtml .= "<div class='section'><div class='section-title'>{$title}</div>"
                . "<table class='fields' width='100%' cellpadding='0' cellspacing='2'>{$rows}</table></div>";
        }

        // Render both signatures side-by-side (show section only if at least one exists)
        $sigHtml = '';
        if ($sigInline || $sig2Inline) {
            $sig1Cell = $sigInline
                ? "<img src='" . $sigInline . "' class='sig-img' />"
                : "<span class='sig-empty'>Not provided</span>";
            $sig2Cell = $sig2Inline
                ? "<img src='" . $sig2Inline . "' class='sig-img' />"
                : "<span class='sig-empty'>Not provided</span>";
            $sigHtml = "<div class='section'><div class='section-title'>Digital Signatures</div>"
                . "<table width='100%' cellpadding='0' cellspacing='6'><tr>"
                . "<td width='50%' valign='top'><div class='sig-label'>Applicant Signature</div>{$sig1Cell}</td>"
                . "<td width='2%'></td>"
                . "<td width='48%' valign='top'><div class='sig-label'>Co-Applicant Signature</div>{$sig2Cell}</td>"
                . "</tr></table></div>";
        }

        $docsHtml = '';
        if (!empty($leadData['documents'])) {
            $docCells = [];
            foreach ($leadData['documents'] as $doc) {
                $name       = htmlspecialchars($doc['filename'] ?? '');
                $type       = htmlspecialchars($doc['doc_type'] ?? '');
                $docCells[] = "<td class='doc-cell'>{$name} <span class='doc-type'>({$type})</span></td>";
            }
            if (count($docCells) % 2 !== 0) {
                $docCells[] = "<td class='doc-cell empty'></td>";
            }
            $docRows = '';
            foreach (array_chunk($docCells, 2) as $row) {
                $docRows .= '<tr>' . implode('', $row) . '</tr>';
            }
            $docsHtml = "<div class='section'><div class='section-title'>Uploaded Documents</div>"
                . "<table class='doc-table' width='100%' cellpadding='0' cellspacing='2'>{$docRows}</table></div>";
        }

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Funding Application &mdash; {$company}</title>
<style>
  @page { size: A4 portrait; margin: 8mm 10mm; }
  * { margin: 0; padding: 0; }
  body { font-family: Arial, DejaVu Sans, sans-serif; color: #1e293b; background: #fff; font-size: 9px; line-height: 1.35; }
  .page { width: 100%; background: #fff; }

  /* Header */
  .header { background: #0f172a; color: #fff; padding: 0; }
  .header-table { width: 100%; }
  .header-logo-cell { width: 38px; padding: 6px 0 6px 10px; vertical-align: middle; }
  .header-text-cell { padding: 6px 10px 6px 8px; vertical-align: middle; }
  .logo { max-height: 26px; max-width: 70px; }
  .logo-placeholder { width: 28px; height: 28px; background: #4f46e5; text-align: center; font-size: 12px; font-weight: 800; color: #fff; line-height: 28px; }
  .company-name { font-size: 11px; font-weight: 700; color: #fff; }
  .company-sub  { font-size: 7px; color: #94a3b8; margin-top: 1px; }
  .badge { background: #4f46e5; color: #fff; font-size: 6px; font-weight: 700; padding: 1px 4px; margin-top: 2px; display: inline-block; }

  /* Body */
  .body { padding: 5px 0 0; }

  /* Sections */
  .section { margin-bottom: 5px; }
  .section-title { font-size: 7px; font-weight: 700; color: #4f46e5; text-transform: uppercase; letter-spacing: 0.6px; padding-bottom: 2px; border-bottom: 1px solid #c7d2fe; margin-bottom: 3px; }

  /* Field table — 3 columns via HTML table (dompdf-safe) */
  .fields { border-collapse: separate; border-spacing: 2px; }
  .field { width: 33.33%; background: #f8fafc; border: 1px solid #e2e8f0; padding: 3px 5px; vertical-align: top; }
  .field.empty { background: transparent; border-color: transparent; }
  .lbl { font-size: 6px; font-weight: 700; color: #94a3b8; text-transform: uppercase; letter-spacing: 0.3px; display: block; margin-bottom: 1px; }
  .val { font-size: 8.5px; font-weight: 500; color: #1e293b; }

  /* Signatures */
  .sig-img { max-height: 36px; border: 1px solid #e2e8f0; padding: 2px; background: #fff; }
  .sig-label { font-size: 6.5px; font-weight: 700; color: #94a3b8; text-transform: uppercase; letter-spacing: 0.3px; margin-bottom: 3px; }
  .sig-empty { font-size: 7.5px; color: #94a3b8; font-style: italic; }

  /* Documents — 2 columns via HTML table */
  .doc-table { border-collapse: separate; border-spacing: 2px; }
  .doc-cell { width: 50%; background: #f8fafc; border: 1px solid #e2e8f0; padding: 2px 6px; font-size: 7.5px; vertical-align: top; }
  .doc-cell.empty { background: transparent; border-color: transparent; }
  .doc-type { color: #64748b; }

  /* Footer */
  .footer { border-top: 1px solid #e2e8f0; margin-top: 4px; padding-top: 3px; font-size: 6.5px; color: #94a3b8; }
  .footer-table { width: 100%; }
  .footer-right { text-align: right; }
</style>
</head>
<body>
<div class="page">
  <div class="header">
    <table class="header-table" cellpadding="0" cellspacing="0">
      <tr>
        <td class="header-logo-cell">{$logoHtml}</td>
        <td class="header-text-cell">
          <div class="company-name">{$company}</div>
          <div class="company-sub">Funding Application &mdash; {$date}</div>
          <span class="badge">CONFIDENTIAL</span>
        </td>
      </tr>
    </table>
  </div>
  <div class="body">
    {$sectionsHtml}
    {$sigHtml}
    {$docsHtml}
  </div>
  <div class="footer">
    <table class="footer-table" cellpadding="0" cellspacing="0">
      <tr>
        <td>Submitted on {$date}</td>
        <td class="footer-right">{$email}&nbsp;&nbsp;{$phone}</td>
      </tr>
    </table>
  </div>
</div>
</body>
</html>
HTML;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // MERCHANT PORTAL
    // ─────────────────────────────────────────────────────────────────────────

    public function resolveLeadToken(string $token): array
    {
        // ── Fast path: master lead_token_map index (single query, no per-client DB open) ──
        // This avoids opening 50+ client connections per request (MySQL max_connections risk).
        $mapped = DB::table('lead_token_map')
            ->where('lead_token', $token)
            ->select('client_id', 'lead_id')
            ->first();

        if ($mapped) {
            $result = $this->fetchLeadFromClient((int) $mapped->client_id, $token);
            if ($result !== null) return $result;
            // Map entry exists but lead was deleted — fall through to full scan
        }

        // ── Fallback scan: iterate all clients (for leads created before the index existed) ──
        // Opens one DB connection at a time and closes it immediately on success.
        $clients = DB::table('clients')->where('is_deleted', 0)->orderBy('id')->pluck('id');

        foreach ($clients as $clientId) {
            try {
                $result = $this->fetchLeadFromClient((int) $clientId, $token);
                if ($result !== null) {
                    // Back-fill the index so next access is instant
                    $lead = $result[0];
                    DB::table('lead_token_map')->insertOrIgnore([
                        'lead_token' => $token,
                        'client_id'  => $clientId,
                        'lead_id'    => $lead->id,
                        'created_at' => now(),
                    ]);
                    return $result;
                }
            } catch (\Throwable $e) {
                \Log::warning("[resolveLeadToken] client_{$clientId} error: " . $e->getMessage());
                continue;
            }
        }

        throw new \RuntimeException('Application not found or link expired.', 404);
    }

    /**
     * Attempt to load a lead by token from a single client DB.
     * Returns [$lead, $clientId, $client] on success, null if not found.
     * Checks crm_leads (EAV), crm_merchant_portals (portal tokens), and crm_lead_data (legacy).
     */
    private function fetchLeadFromClient(int $clientId, string $token): ?array
    {
        $conn = "mysql_{$clientId}";

        // Check crm_leads (new EAV architecture)
        if (DB::connection($conn)->getSchemaBuilder()->hasTable('crm_leads')) {
            $lead = DB::connection($conn)->table('crm_leads')
                ->where(function ($q) use ($token) {
                    $q->where('lead_token', $token)->orWhere('unique_token', $token);
                })
                ->whereNull('deleted_at')
                ->first();

            if ($lead) {
                return [$lead, $clientId, $this->loadClientSettings($conn, $clientId)];
            }
        }

        // Check crm_merchant_portals — portal token may differ from crm_leads.lead_token
        // (happens when portal was generated before lead_token sync was added)
        if (DB::connection($conn)->getSchemaBuilder()->hasTable('crm_merchant_portals')) {
            $portal = DB::connection($conn)->table('crm_merchant_portals')
                ->where('token', $token)
                ->where('status', 1)
                ->first();

            if ($portal) {
                $lead = DB::connection($conn)->table('crm_leads')
                    ->where('id', $portal->lead_id)
                    ->whereNull('deleted_at')
                    ->first();

                if ($lead) {
                    // Sync crm_leads.lead_token so future fast-path lookups work
                    DB::connection($conn)->table('crm_leads')
                        ->where('id', $lead->id)
                        ->update(['lead_token' => $token, 'unique_token' => $token, 'updated_at' => now()]);
                    $lead->lead_token   = $token;
                    $lead->unique_token = $token;

                    return [$lead, $clientId, $this->loadClientSettings($conn, $clientId)];
                }
            }
        }

        // Check crm_lead_data (legacy architecture — client_78 and similar)
        if (DB::connection($conn)->getSchemaBuilder()->hasTable('crm_lead_data')) {
            $cols = DB::connection($conn)->getSchemaBuilder()->getColumnListing('crm_lead_data');
            if (in_array('lead_token', $cols, true)) {
                $lead = DB::connection($conn)->table('crm_lead_data')
                    ->where('lead_token', $token)
                    ->whereNull('deleted_at')
                    ->first();

                if ($lead) {
                    return [$lead, $clientId, $this->loadClientSettings($conn, $clientId)];
                }
            }
        }

        return null;
    }

    private function loadClientSettings(string $conn, int $clientId): object
    {
        $client = DB::connection($conn)->table('crm_system_setting')->orderBy('id')->first();
        if (!$client) {
            $mc = DB::table('clients')->where('id', $clientId)->first();
            $client = (object) [
                'company_name' => $mc->company_name ?? '', 'company_email' => null,
                'company_phone' => null, 'company_address' => null, 'city' => null,
                'state' => null, 'zipcode' => null, 'logo' => $mc->logo ?? null,
                'company_domain' => null,
            ];
        }
        return $client;
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
                foreach (['first_name','last_name','email','phone_number','dob','city','state','country','address','company_name','signature_image','owner_2_signature_image'] as $col) {
                    if (!empty(((array) $row)[$col])) $legacyData[$col] = ((array) $row)[$col];
                }
            }
        }

        $merged = array_merge($legacyData, $eavData);

        // Build backend-served signature URLs so the frontend never needs to
        // construct paths into non-public app storage directories.
        $base          = rtrim(env('APP_URL'), '/');
        $token         = $lead->lead_token;
        $signatureUrl  = !empty($merged['signature_image'])
            ? "{$base}/public/lead/{$token}/signature"
            : null;
        $signatureUrl2 = !empty($merged['owner_2_signature_image'])
            ? "{$base}/public/lead/{$token}/signature2"
            : null;

        return [
            'id'              => $lead->id,
            'lead_status'     => $lead->lead_status,
            'lead_type'       => $lead->lead_type,
            'lead_token'      => $token,
            'affiliate_code'  => $lead->affiliate_code ?? null,
            'created_at'      => $lead->created_at,
            'fields'          => $merged,
            'signature_url'   => $signatureUrl,
            'signature_url_2' => $signatureUrl2,
            'documents'       => $this->getDocuments($clientId, $leadId),
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
    public function serveLeadSignature(int $clientId, int $leadId, string $fieldKey = 'signature_image'): array
    {
        $conn    = "mysql_{$clientId}";
        $sigPath = null;

        if (DB::connection($conn)->getSchemaBuilder()->hasTable('crm_lead_values')) {
            $sigPath = DB::connection($conn)->table('crm_lead_values')
                ->where('lead_id', $leadId)
                ->where('field_key', $fieldKey)
                ->value('field_value');
        }

        // Fallback: try legacy crm_lead_data column
        if (!$sigPath && DB::connection($conn)->getSchemaBuilder()->hasTable('crm_lead_data')) {
            $sigPath = DB::connection($conn)->table('crm_lead_data')
                ->where('id', $leadId)
                ->value($fieldKey);
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

    /**
     * Serve a lead document inline — never redirects to direct storage URL.
     * Validates token ownership. Returns [absPath, mimeType, filename].
     */
    public function serveDocumentInline(string $token, int $docId): array
    {
        [$lead, $clientId] = $this->resolveLeadToken($token);
        $conn = "mysql_{$clientId}";

        if (!DB::connection($conn)->getSchemaBuilder()->hasTable('crm_documents')) {
            throw new \RuntimeException('Document not found.', 404);
        }

        $doc = DB::connection($conn)->table('crm_documents')
            ->where('id', $docId)->where('lead_id', $lead->id)->whereNull('deleted_at')->first();

        if (!$doc) {
            throw new \RuntimeException('Document not found.', 404);
        }

        $filePath = $doc->file_path ?? '';
        $filename = $doc->file_name ?? basename($filePath);

        // Convert public URL to physical path (never redirect)
        $appUrl = rtrim(env('APP_URL'), '/');
        if (str_starts_with($filePath, $appUrl . '/storage/')) {
            $relativePath = ltrim(substr($filePath, strlen($appUrl . '/storage/')), '/');
            $absPath = storage_path('app/public/' . $relativePath);
        } elseif (!str_starts_with($filePath, 'http')) {
            // Legacy: relative path on disk
            $absPath = TenantStorageService::getPath($clientId, $filePath);
            if (!file_exists($absPath)) {
                $absPath = storage_path('app/public/' . $filePath);
            }
        } else {
            // External URL — cannot stream inline safely
            throw new \RuntimeException('This document cannot be viewed inline.', 400);
        }

        if (!file_exists($absPath)) {
            throw new \RuntimeException('Document file not found.', 404);
        }

        $mime = mime_content_type($absPath) ?: 'application/pdf';
        return [$absPath, $mime, $filename];
    }

    /**
     * Delete a lead document (soft-delete DB row + delete physical file).
     */
    public function deleteLeadDocument(object $lead, int $clientId, int $docId): void
    {
        $conn = "mysql_{$clientId}";

        if (!DB::connection($conn)->getSchemaBuilder()->hasTable('crm_documents')) {
            throw new \RuntimeException('Document not found.', 404);
        }

        $doc = DB::connection($conn)->table('crm_documents')
            ->where('id', $docId)->where('lead_id', $lead->id)->whereNull('deleted_at')->first();

        if (!$doc) {
            throw new \RuntimeException('Document not found.', 404);
        }

        // Delete physical file from storage
        $filePath = $doc->file_path ?? '';
        $appUrl   = rtrim(env('APP_URL'), '/');
        if (str_starts_with($filePath, $appUrl . '/storage/')) {
            $relativePath = ltrim(substr($filePath, strlen($appUrl . '/storage/')), '/');
            \Storage::disk('public')->delete($relativePath);
        }

        // Soft-delete the DB row
        DB::connection($conn)->table('crm_documents')
            ->where('id', $docId)
            ->update(['deleted_at' => now()]);
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

    /**
     * Normalise field types from the new crm_labels table.
     * crm_labels uses: text|number|email|phone_number|date|textarea|dropdown|checkbox|radio
     * Frontend expects: text|number|email|tel|date|textarea|select|checkbox|ssn
     */
    private function normalizeEavFieldType(string $type): string
    {
        return [
            'phone_number' => 'tel',
            'phone'        => 'tel',
            'number'       => 'number',
            'date'         => 'date',
            'email'        => 'email',
            'text'         => 'text',
            'textarea'     => 'textarea',
            'dropdown'     => 'select',
            'checkbox'     => 'select',
            'radio'        => 'select',
            'ssn'          => 'ssn',
        ][$type] ?? 'text';
    }

    /**
     * Parse options stored as JSON in crm_labels.options.
     */
    private function parseJsonOptions(?string $options): array
    {
        if (empty($options)) return [];
        $decoded = json_decode($options, true);
        return is_array($decoded) ? $decoded : [];
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
                // file_name can be null for legacy uploads — fall back to basename of file_path
                $filename = $d->file_name ?? basename((string) ($d->file_path ?? ''));
                if (empty($filename)) $filename = 'document_' . $d->id;

                return [
                    'id'       => $d->id,
                    'filename' => $filename,
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
