<?php

namespace App\Services;

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
    // Fields excluded from the public apply form (sensitive)
    private const EXCLUDED_APPLY_KEYS = ['ssn', 'credit_score', 'unique_url', 'unique_token', 'signature_image', 'owner_2_ssn'];

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

    /**
     * Resolve an affiliate code to a user + client.
     * Returns [user, clientId, client] or throws.
     */
    public function resolveAffiliate(string $code): array
    {
        $user = DB::table('users')
            ->where('affiliate_code', $code)
            ->whereNull('is_deleted')
            ->orWhere(function ($q) use ($code) {
                $q->where('affiliate_code', $code)->where('is_deleted', 0);
            })
            ->select('id', 'first_name', 'last_name', 'email', 'mobile', 'parent_id', 'affiliate_code')
            ->first();

        if (!$user) {
            throw new \RuntimeException('Affiliate link not found or expired.', 404);
        }

        $clientId = $user->parent_id;

        // Load company data from crm_system_setting (client DB)
        $client = DB::connection("mysql_{$clientId}")
            ->table('crm_system_setting')
            ->orderBy('id')
            ->first();

        if (!$client) {
            // Fallback: create a minimal object from master clients table
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

    public function getCompanyBranding(object $client): array
    {
        // $client is either a `clients` row (master) or a `crm_system_setting` row (client DB)
        // Normalise: support both schemas
        $logoRaw    = $client->logo ?? null;
        $logoUrl    = null;
        if ($logoRaw) {
            $logoUrl = str_starts_with($logoRaw, 'http')
                ? $logoRaw
                : rtrim(env('APP_URL'), '/') . '/logo/' . $logoRaw;
        }

        // company_domain (crm_system_setting) OR website_url (clients)
        $websiteUrl = $client->company_domain ?? $client->website_url ?? null;

        // company_email (crm_system_setting) OR support_email (clients)
        $email = $client->company_email ?? $client->support_email ?? null;

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

    /**
     * Load form sections/fields from crm_label for a given client.
     * Excludes sensitive fields. Grouped by heading.
     */
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

            // Skip internal/sensitive fields from public form
            if (!$includeAll && in_array($key, self::EXCLUDED_APPLY_KEYS, true)) {
                continue;
            }

            $heading  = $lbl->heading_type ?: 'other';
            $section  = self::SECTION_LABELS[$heading] ?? ucfirst($heading);

            if (!isset($grouped[$section])) {
                $grouped[$section] = [];
            }

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

        // Build ordered sections: Business first, then Owner, then others
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
    // LEAD CREATION (apply form submission)
    // ─────────────────────────────────────────────────────────────────────────

    public function createLead(int $clientId, object $affiliateUser, array $formData): array
    {
        $conn      = "mysql_{$clientId}";
        $leadToken = Str::random(32);  // secure random token

        // Resolve crm_label column mapping for this client
        $columnMap = $this->buildColumnMap($conn);

        $now = now();

        // ── Insert into crm_leads ─────────────────────────────────────────────
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

        // ── Store EAV values (crm_lead_values) if table exists ───────────────
        if (DB::connection($conn)->getSchemaBuilder()->hasTable('crm_lead_values')) {
            $eavRows = [];
            foreach ($formData as $fieldKey => $value) {
                if ($value === null || $value === '') continue;
                $eavRows[] = [
                    'lead_id'    => $leadId,
                    'field_key'  => $fieldKey,
                    'field_value'=> (string) $value,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
            if ($eavRows) {
                DB::connection($conn)->table('crm_lead_values')->insert($eavRows);
            }
        }

        // ── Also write into crm_lead_data direct columns ─────────────────────
        if (DB::connection($conn)->getSchemaBuilder()->hasTable('crm_lead_data')) {
            $legacyData = [
                'id'         => $leadId,
                'lead_status'=> 'new_lead',
                'lead_type'  => 'warm',
                'assigned_to'=> $affiliateUser->id,
                'created_by' => $affiliateUser->id,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            // Map field_key → column_name for direct columns
            foreach ($formData as $fieldKey => $value) {
                if ($value === null || $value === '') continue;

                // Direct column match (first_name, last_name, email, etc.)
                if (isset($columnMap['direct'][$fieldKey])) {
                    $col = $columnMap['direct'][$fieldKey];
                    $legacyData[$col] = $value;
                }
                // label_title_url → column_name mapping
                elseif (isset($columnMap['mapped'][$fieldKey])) {
                    $col = $columnMap['mapped'][$fieldKey];
                    $legacyData[$col] = $value;
                }
            }

            try {
                DB::connection($conn)->table('crm_lead_data')->insert($legacyData);
            } catch (\Throwable $e) {
                // Non-fatal — EAV is the primary store
                \Log::warning("PublicApp: crm_lead_data insert failed for lead {$leadId}: " . $e->getMessage());
            }
        }

        // ── Log activity ─────────────────────────────────────────────────────
        $this->logActivity($conn, $leadId, $affiliateUser->id, 'affiliate_application', 'Lead created via affiliate link.');

        // ── Build merchant URL ────────────────────────────────────────────────
        $websiteUrl  = DB::table('clients')->where('id', $clientId)->value('website_url')
            ?? env('APP_FRONTEND_URL', env('APP_URL'));
        $merchantUrl = rtrim($websiteUrl, '/') . '/merchant/' . $leadToken;

        return [
            'lead_id'      => $leadId,
            'lead_token'   => $leadToken,
            'merchant_url' => $merchantUrl,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // MERCHANT PORTAL
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Resolve a lead_token to [lead, clientId, client].
     */
    public function resolveLeadToken(string $token): array
    {
        // Search across clients — find the client DB that owns this token
        $clients = DB::table('clients')->where('is_deleted', 0)->pluck('id');

        foreach ($clients as $clientId) {
            $conn = "mysql_{$clientId}";
            try {
                if (!DB::connection($conn)->getSchemaBuilder()->hasTable('crm_leads')) continue;
                $lead = DB::connection($conn)->table('crm_leads')
                    ->where('lead_token', $token)
                    ->whereNull('deleted_at')
                    ->first();
                if ($lead) {
                    $client = DB::connection($conn)->table('crm_system_setting')->orderBy('id')->first();
                    if (!$client) {
                        $mc = DB::table('clients')->where('id', $clientId)->first();
                        $client = (object)['company_name'=>$mc->company_name??'','company_email'=>null,'company_phone'=>null,'company_address'=>null,'city'=>null,'state'=>null,'zipcode'=>null,'logo'=>$mc->logo??null,'company_domain'=>null];
                    }
                    return [$lead, $clientId, $client];
                }
            } catch (\Throwable $e) {
                continue;
            }
        }

        throw new \RuntimeException('Application not found or link expired.', 404);
    }

    /**
     * Load full lead data for merchant portal (lead + EAV values).
     */
    public function getMerchantLeadData(object $lead, int $clientId): array
    {
        $conn = "mysql_{$clientId}";
        $leadId = $lead->id;

        // Load EAV values
        $eavData = [];
        if (DB::connection($conn)->getSchemaBuilder()->hasTable('crm_lead_values')) {
            $eavData = DB::connection($conn)
                ->table('crm_lead_values')
                ->where('lead_id', $leadId)
                ->pluck('field_value', 'field_key')
                ->toArray();
        }

        // Merge with lead_data direct columns
        $legacyData = [];
        if (DB::connection($conn)->getSchemaBuilder()->hasTable('crm_lead_data')) {
            $row = DB::connection($conn)->table('crm_lead_data')->where('id', $leadId)->first();
            if ($row) {
                $columnMap = $this->buildColumnMap($conn);
                // Reverse: column_name → field_key
                foreach ($columnMap['mapped'] as $fieldKey => $col) {
                    $val = ((array) $row)[$col] ?? null;
                    if ($val !== null && $val !== '') {
                        $legacyData[$fieldKey] = $val;
                    }
                }
                // Direct columns
                foreach (['first_name','last_name','email','phone_number','dob','city','state','country','address','company_name'] as $col) {
                    if (!empty(((array) $row)[$col])) {
                        $legacyData[$col] = ((array) $row)[$col];
                    }
                }
            }
        }

        $merged = array_merge($legacyData, $eavData);

        // Load uploaded documents
        $documents = $this->getDocuments($clientId, $leadId);

        return [
            'id'               => $lead->id,
            'lead_status'      => $lead->lead_status,
            'lead_type'        => $lead->lead_type,
            'lead_token'       => $lead->lead_token,
            'affiliate_code'   => $lead->affiliate_code ?? null,
            'created_at'       => $lead->created_at,
            'fields'           => $merged,
            'documents'        => $documents,
        ];
    }

    /**
     * Update lead data from merchant portal.
     */
    public function updateMerchantLead(object $lead, int $clientId, array $formData): void
    {
        $conn   = "mysql_{$clientId}";
        $leadId = $lead->id;
        $now    = now();

        // Update EAV values
        if (DB::connection($conn)->getSchemaBuilder()->hasTable('crm_lead_values')) {
            foreach ($formData as $key => $value) {
                DB::connection($conn)->table('crm_lead_values')->updateOrInsert(
                    ['lead_id' => $leadId, 'field_key' => $key],
                    ['field_value' => $value, 'updated_at' => $now, 'created_at' => $now]
                );
            }
        }

        // Update crm_lead_data
        if (DB::connection($conn)->getSchemaBuilder()->hasTable('crm_lead_data')) {
            $columnMap  = $this->buildColumnMap($conn);
            $updateData = ['updated_at' => $now];
            foreach ($formData as $fieldKey => $value) {
                if (isset($columnMap['direct'][$fieldKey])) {
                    $updateData[$columnMap['direct'][$fieldKey]] = $value;
                } elseif (isset($columnMap['mapped'][$fieldKey])) {
                    $updateData[$columnMap['mapped'][$fieldKey]] = $value;
                }
            }
            try {
                DB::connection($conn)->table('crm_lead_data')->where('id', $leadId)->update($updateData);
            } catch (\Throwable $e) {
                \Log::warning("Merchant update crm_lead_data: " . $e->getMessage());
            }
        }

        $this->logActivity($conn, $leadId, 0, 'merchant_update', 'Merchant updated application.');
    }

    /**
     * Store an uploaded document from the merchant portal.
     */
    public function storeDocument(object $lead, int $clientId, $file, string $docType): array
    {
        $dir      = "crm_documents/{$clientId}/{$lead->id}";
        $filename = time() . '_' . Str::random(8) . '.' . $file->getClientOriginalExtension();
        $file->storeAs($dir, $filename, 'public');

        $conn = "mysql_{$clientId}";
        $now  = now();

        if (DB::connection($conn)->getSchemaBuilder()->hasTable('crm_lead_documents')) {
            DB::connection($conn)->table('crm_lead_documents')->insert([
                'lead_id'     => $lead->id,
                'file_name'   => $filename,
                'file_path'   => $dir . '/' . $filename,
                'doc_type'    => $docType,
                'uploaded_by' => 0, // merchant (no auth)
                'created_at'  => $now,
                'updated_at'  => $now,
            ]);
        }

        return [
            'filename' => $filename,
            'path'     => $dir . '/' . $filename,
            'url'      => rtrim(env('APP_URL'), '/') . '/storage/' . $dir . '/' . $filename,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // AFFILIATE CODE MANAGEMENT (for CRM backend)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Generate a unique affiliate code for a user.
     * Format: {firstname}{lastname_initial}{4_random_digits}
     */
    public function generateAffiliateCode(object $user): string
    {
        $base = strtolower(
            preg_replace('/[^a-z0-9]/i', '', $user->first_name) .
            substr(preg_replace('/[^a-z0-9]/i', '', $user->last_name), 0, 4)
        );
        $base = $base ?: 'agent';

        $code  = $base . rand(100, 9999);
        $tries = 0;
        while (DB::table('users')->where('affiliate_code', $code)->exists() && $tries < 20) {
            $code = $base . rand(1000, 99999);
            $tries++;
        }

        return $code;
    }

    /**
     * Build the affiliate link URL for a user.
     * Domain comes from clients.website_url — never hardcoded.
     */
    public function buildAffiliateUrl(int $clientId, string $affiliateCode): string
    {
        $websiteUrl = DB::connection("mysql_{$clientId}")
            ->table('crm_system_setting')
            ->orderBy('id')
            ->value('company_domain')
            ?? env('APP_FRONTEND_URL', '');
        return rtrim($websiteUrl, '/') . '/apply/' . $affiliateCode;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // HELPERS
    // ─────────────────────────────────────────────────────────────────────────

    private function buildColumnMap(string $conn): array
    {
        // Direct column match (label_title_url === column_name)
        $direct = [
            'first_name'   => 'first_name',
            'last_name'    => 'last_name',
            'email'        => 'email',
            'phone_number' => 'phone_number',
            'mobile'       => 'phone_number',
            'dob'          => 'dob',
            'city'         => 'city',
            'state'        => 'state',
            'country'      => 'country',
            'address'      => 'address',
            'company_name' => 'company_name',
        ];

        // label_title_url → column_name from crm_label
        $mapped = [];
        if (DB::connection($conn)->getSchemaBuilder()->hasTable('crm_label')) {
            $rows = DB::connection($conn)->table('crm_label')
                ->whereNotNull('label_title_url')
                ->where('label_title_url', '!=', '')
                ->pluck('column_name', 'label_title_url');
            $mapped = $rows->toArray();
        }

        return compact('direct', 'mapped');
    }

    private function normalizeFieldType(string $type): string
    {
        $map = [
            'phone_number' => 'tel',
            'number'       => 'number',
            'date'         => 'date',
            'email'        => 'email',
            'text'         => 'text',
            'select'       => 'select',
            'select_state' => 'select',
            'textarea'     => 'textarea',
            'checkbox'     => 'checkbox',
        ];
        return $map[$type] ?? 'text';
    }

    private function parseOptions(?string $values): array
    {
        if (empty($values)) return [];
        $decoded = json_decode($values, true);
        if (is_array($decoded)) return $decoded;
        // comma-separated string
        return array_map('trim', explode(',', $values));
    }

    private function getDocuments(int $clientId, int $leadId): array
    {
        $conn = "mysql_{$clientId}";
        if (!DB::connection($conn)->getSchemaBuilder()->hasTable('crm_lead_documents')) {
            return [];
        }
        return DB::connection($conn)->table('crm_lead_documents')
            ->where('lead_id', $leadId)
            ->get()
            ->map(function ($d) {
                return [
                    'id'       => $d->id,
                    'filename' => $d->file_name,
                    'doc_type' => $d->doc_type,
                    'url'      => rtrim(env('APP_URL'), '/') . '/storage/' . $d->file_path,
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
                'lead_id'     => $leadId,
                'user_id'     => $userId,
                'activity'    => $type,
                'description' => $note,
                'created_at'  => now(),
                'updated_at'  => now(),
            ]);
        } catch (\Throwable $e) { /* non-fatal */ }
    }

    private function defaultSections(): array
    {
        return [
            [
                'title' => 'Business Information',
                'fields' => [
                    ['key' => 'legal_company_name', 'label' => 'Business Name',       'type' => 'text',   'required' => true,  'options' => []],
                    ['key' => 'business_address',   'label' => 'Business Address',     'type' => 'text',   'required' => false, 'options' => []],
                    ['key' => 'business_city',      'label' => 'City',                 'type' => 'text',   'required' => false, 'options' => []],
                    ['key' => 'business_state',     'label' => 'State',                'type' => 'text',   'required' => false, 'options' => []],
                    ['key' => 'industry',            'label' => 'Industry',             'type' => 'text',   'required' => false, 'options' => []],
                    ['key' => 'amount_requested',   'label' => 'Amount Requested ($)', 'type' => 'number', 'required' => false, 'options' => []],
                    ['key' => 'use_of_funds',       'label' => 'Use of Funds',         'type' => 'text',   'required' => false, 'options' => []],
                ],
            ],
            [
                'title' => 'Owner Information',
                'fields' => [
                    ['key' => 'first_name', 'label' => 'First Name', 'type' => 'text',  'required' => true,  'options' => []],
                    ['key' => 'last_name',  'label' => 'Last Name',  'type' => 'text',  'required' => true,  'options' => []],
                    ['key' => 'email',      'label' => 'Email',      'type' => 'email', 'required' => true,  'options' => []],
                    ['key' => 'mobile',     'label' => 'Phone',      'type' => 'tel',   'required' => true,  'options' => []],
                    ['key' => 'dob',        'label' => 'Date of Birth', 'type' => 'date', 'required' => false, 'options' => []],
                ],
            ],
        ];
    }
}
