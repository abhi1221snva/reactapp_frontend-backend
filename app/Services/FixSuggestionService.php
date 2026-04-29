<?php

namespace App\Services;

/**
 * FixSuggestionService
 *
 * Enriches an array of parsed errors (from ErrorParserService) with:
 *   - The current lead field value (looked up from EAV data)
 *   - An auto-fix value where a deterministic conversion is possible
 *   - A plain-English suggestion string
 *   - Whether the fix can be applied automatically
 *
 * Completely lender-agnostic — no hardcoded vendor logic.
 */
class FixSuggestionService
{
    // ── Public API ─────────────────────────────────────────────────────────────

    /**
     * Enrich an array of parsed errors with fix suggestions.
     *
     * @param  array<int, array{field: string, fix_type: string, expected: string, ...}> $errors   Output of ErrorParserService::parse()
     * @param  array<string, mixed> $leadData  Flat EAV key→value map (field_key → value)
     * @return array<int, array{
     *   field:          string,
     *   raw_message:    string,
     *   message:        string,
     *   fix_type:       string,
     *   expected:       string,
     *   path_parts:     string[],
     *   crm_key:        string,
     *   current_value:  mixed,
     *   auto_fix_value: string|null,
     *   can_auto_fix:   bool,
     *   suggestion:     string,
     * }>
     */
    public function suggest(array $errors, array $leadData): array
    {
        // Expand compound address fields into sub-fields before processing
        $errors = $this->expandCompoundFields($errors);

        $enriched = [];

        foreach ($errors as $error) {
            $field        = $error['field']    ?? '';
            $fixType      = $error['fix_type'] ?? 'unknown';

            // Map lender dot-path → CRM field key (heuristic)
            $crmKey       = $this->toCrmKey($field);

            // Resolve current value: try CRM key first, then the raw path
            $currentValue = $leadData[$crmKey] ?? ($leadData[$field] ?? null);

            $autoFixValue = null;
            $canAutoFix   = false;
            $suggestion   = $error['expected'] ?? '';

            // ── State code ─────────────────────────────────────────────────────
            if ($fixType === 'state_code') {
                if ($currentValue !== null && $currentValue !== '' && strlen((string) $currentValue) > 2) {
                    $abbrev = $this->stateToAbbrev((string) $currentValue);
                    if ($abbrev !== null) {
                        $autoFixValue = $abbrev;
                        $canAutoFix   = true;
                        $suggestion   = "Convert \"{$currentValue}\" → \"{$abbrev}\"";
                    } else {
                        $suggestion = 'Enter a 2-letter state code (e.g. NY, CA, TX)';
                    }
                } elseif ($currentValue === null || $currentValue === '') {
                    $suggestion = 'Enter a 2-letter state code (e.g. NY, CA, TX)';
                }
            }

            // ── Phone number ──────────────────────────────────────────────────
            if ($fixType === 'phone' && $currentValue !== null && $currentValue !== '') {
                $normalised = $this->normalisePhone((string) $currentValue);
                if ($normalised !== null && $normalised !== (string) $currentValue) {
                    $autoFixValue = $normalised;
                    $canAutoFix   = true;
                    $suggestion   = "Reformat to {$normalised}";
                }
            }

            // ── ZIP code ──────────────────────────────────────────────────────
            if ($fixType === 'zip' && $currentValue !== null && $currentValue !== '') {
                $cleaned = preg_replace('/[^0-9]/', '', (string) $currentValue) ?? '';
                if (strlen($cleaned) >= 5) {
                    $fiveDigit = substr($cleaned, 0, 5);
                    if ($fiveDigit !== (string) $currentValue) {
                        $autoFixValue = $fiveDigit;
                        $canAutoFix   = true;
                        $suggestion   = "Clean to 5-digit ZIP: {$fiveDigit}";
                    }
                }
            }

            // ── EIN / SSN normalise (strip dashes) ────────────────────────────
            if ($fixType === 'ein' && $currentValue !== null && $currentValue !== '') {
                $digits = preg_replace('/[^0-9]/', '', (string) $currentValue) ?? '';
                if (strlen($digits) === 9 && $digits !== (string) $currentValue) {
                    $autoFixValue = $digits;
                    $canAutoFix   = true;
                    $suggestion   = "Strip formatting: {$digits}";
                }
            }

            // ── Required field ────────────────────────────────────────────────
            if ($fixType === 'required') {
                $suggestion = 'This field is required — enter a value to continue';
            }

            $enriched[] = array_merge($error, [
                'crm_key'        => $crmKey,
                'current_value'  => $currentValue !== null ? (string) $currentValue : null,
                'auto_fix_value' => $autoFixValue,
                'can_auto_fix'   => $canAutoFix,
                'suggestion'     => $suggestion,
            ]);
        }

        return $enriched;
    }

    // ── Field Key Resolution ───────────────────────────────────────────────────

    /**
     * Heuristically map a lender's dot-notation path to a CRM EAV field_key.
     *
     *   "owners.0.homeAddress.state" → "home_state"  (last significant segment)
     *   "business.phone"             → "business_phone" (context-aware)
     *   "businessPhone"              → "business_phone"
     *   "zipCode"                    → "zip_code"
     */
    private function toCrmKey(string $path): string
    {
        if ($path === '') {
            return '';
        }

        // Strip numeric index segments
        $parts = array_values(array_filter(
            explode('.', $path),
            fn ($p) => !is_numeric($p)
        ));

        if (empty($parts)) {
            return $path;
        }

        // Full-path map (context-aware — checks parent.child combinations)
        $fullPath = implode('.', $parts);
        static $fullPathMap = [
            'business.phone'                => 'business_phone',
            'business.address'              => 'business_address',
            'business.taxID'                => 'ein',
            'business.taxId'                => 'ein',
            'business.businessInceptionDate'=> 'business_start_date',
            'business.name'                 => 'company_name',
            'business.city'                 => 'business_city',
            'business.state'                => 'business_state',
            'business.zip'                  => 'business_zip',
            'business.zipCode'              => 'business_zip',
            'business.email'                => 'business_email',
            'business.address.addressLine1' => 'business_address',
            'business.address.city'         => 'business_city',
            'business.address.state'        => 'business_state',
            'business.address.zipCode'      => 'business_zip',
            'owners.homeAddress'            => 'home_address',
            'owners.homeAddress.state'      => 'home_state',
            'owners.homeAddress.city'       => 'home_city',
            'owners.homeAddress.zipCode'    => 'home_zip',
            'owners.homeAddress.addressLine1' => 'home_address',
            'owners.dateOfBirth'            => 'date_of_birth',
            'owners.ssn'                    => 'ssn',
            'owners.ownershipPercentage'    => 'ownership_percentage',
            'owners.email'                  => 'email',
            'owners.phone'                  => 'phone',
            'owners.name'                   => 'full_name',
            'owners.firstName'              => 'first_name',
            'owners.lastName'               => 'last_name',
            'selfReported.revenue'          => 'monthly_revenue',
            'selfReported.averageBalance'   => 'average_balance',
        ];

        if (isset($fullPathMap[$fullPath])) {
            return $fullPathMap[$fullPath];
        }

        $last = end($parts);

        // Named heuristics — most-specific first (single segment)
        static $map = [
            'state'                    => 'home_state',
            'homeState'                => 'home_state',
            'businessState'            => 'business_state',
            'zipCode'                  => 'zip_code',
            'zip'                      => 'zip_code',
            'postalCode'               => 'zip_code',
            'phone'                    => 'phone',
            'phoneNumber'              => 'phone',
            'businessPhone'            => 'business_phone',
            'cellPhone'                => 'cell_phone',
            'mobilePhone'              => 'cell_phone',
            'email'                    => 'email',
            'emailAddress'             => 'email',
            'businessEmail'            => 'business_email',
            'firstName'                => 'first_name',
            'lastName'                 => 'last_name',
            'ssn'                      => 'ssn',
            'socialSecurity'           => 'ssn',
            'ein'                      => 'ein',
            'fein'                     => 'ein',
            'taxId'                    => 'ein',
            'taxID'                    => 'ein',
            'dob'                      => 'date_of_birth',
            'dateOfBirth'              => 'date_of_birth',
            'businessName'             => 'business_name',
            'legalName'                => 'legal_name',
            'address'                  => 'address',
            'addressLine1'             => 'address',
            'streetAddress'            => 'address',
            'homeAddress'              => 'home_address',
            'city'                     => 'city',
            'businessCity'             => 'business_city',
            'ownershipPercentage'      => 'ownership_percentage',
            'revenue'                  => 'monthly_revenue',
            'selfReportedRevenue'      => 'monthly_revenue',
            'averageBalance'           => 'average_balance',
            'homePhone'                => 'phone_number',
            'externalCustomerId'       => '_system_lead_id',
            'businessInceptionDate'    => 'business_start_date',
            'businessStartDate'        => 'business_start_date',
            'bizStartDate'             => 'business_start_date',
        ];

        if (isset($map[$last])) {
            return $map[$last];
        }

        // Context-aware fallback: if parent is "business", prefix with "business_"
        if (count($parts) >= 2) {
            $parent = $parts[count($parts) - 2];
            if ($parent === 'business') {
                $snake = strtolower(preg_replace('/([A-Z])/', '_$1', $last) ?? $last);
                return 'business_' . ltrim($snake, '_');
            }
        }

        // camelCase → snake_case
        return strtolower(preg_replace('/([A-Z])/', '_$1', $last) ?? $last);
    }

    // ── Converters ────────────────────────────────────────────────────────────

    /**
     * Convert a US state full name → 2-letter USPS abbreviation.
     * Returns null when the input is not a recognised state name.
     */
    private function stateToAbbrev(string $value): ?string
    {
        static $map = [
            'alabama'              => 'AL', 'alaska'               => 'AK',
            'arizona'              => 'AZ', 'arkansas'             => 'AR',
            'california'           => 'CA', 'colorado'             => 'CO',
            'connecticut'          => 'CT', 'delaware'             => 'DE',
            'florida'              => 'FL', 'georgia'              => 'GA',
            'hawaii'               => 'HI', 'idaho'                => 'ID',
            'illinois'             => 'IL', 'indiana'              => 'IN',
            'iowa'                 => 'IA', 'kansas'               => 'KS',
            'kentucky'             => 'KY', 'louisiana'            => 'LA',
            'maine'                => 'ME', 'maryland'             => 'MD',
            'massachusetts'        => 'MA', 'michigan'             => 'MI',
            'minnesota'            => 'MN', 'mississippi'          => 'MS',
            'missouri'             => 'MO', 'montana'              => 'MT',
            'nebraska'             => 'NE', 'nevada'               => 'NV',
            'new hampshire'        => 'NH', 'new jersey'           => 'NJ',
            'new mexico'           => 'NM', 'new york'             => 'NY',
            'north carolina'       => 'NC', 'north dakota'         => 'ND',
            'ohio'                 => 'OH', 'oklahoma'             => 'OK',
            'oregon'               => 'OR', 'pennsylvania'         => 'PA',
            'rhode island'         => 'RI', 'south carolina'       => 'SC',
            'south dakota'         => 'SD', 'tennessee'            => 'TN',
            'texas'                => 'TX', 'utah'                 => 'UT',
            'vermont'              => 'VT', 'virginia'             => 'VA',
            'washington'           => 'WA', 'west virginia'        => 'WV',
            'wisconsin'            => 'WI', 'wyoming'              => 'WY',
            'district of columbia' => 'DC',
        ];

        return $map[strtolower(trim($value))] ?? null;
    }

    /**
     * Normalise a phone number to E.164 format for the US (+1XXXXXXXXXX).
     * Returns null if a clean 10- or 11-digit number cannot be extracted.
     */
    private function normalisePhone(string $value): ?string
    {
        $digits = preg_replace('/[^0-9]/', '', $value) ?? '';

        if (strlen($digits) === 11 && $digits[0] === '1') {
            return '+' . $digits;
        }

        if (strlen($digits) === 10) {
            return '+1' . $digits;
        }

        return null;
    }

    // ── Compound Field Expansion ────────────────────────────────────────────

    /**
     * Compound address fields (OnDeck expects objects, not strings).
     * When we see "business.address must not be null", expand into individual
     * sub-field errors so the QuickFixModal shows separate inputs for each part.
     */
    private function expandCompoundFields(array $errors): array
    {
        $expanded = [];

        // Compound field definitions: API path → sub-fields with their expected hints
        $compounds = [
            'business.address' => [
                ['field' => 'business.address.addressLine1', 'fix_type' => 'required', 'expected' => 'Street address (e.g. 123 Main St)', 'message' => 'Business street address is required'],
                ['field' => 'business.address.city',         'fix_type' => 'required', 'expected' => 'City name',                          'message' => 'Business city is required'],
                ['field' => 'business.address.state',        'fix_type' => 'state_code', 'expected' => '2-letter US state code (e.g. NY, CA)', 'message' => 'Business state is required'],
                ['field' => 'business.address.zipCode',      'fix_type' => 'zip',      'expected' => '5-digit ZIP code (e.g. 10001)',      'message' => 'Business ZIP code is required'],
            ],
            'owners.homeAddress' => [
                ['field' => 'owners.homeAddress.addressLine1', 'fix_type' => 'required', 'expected' => 'Street address (e.g. 456 Oak Ave)', 'message' => 'Home street address is required'],
                ['field' => 'owners.homeAddress.city',         'fix_type' => 'required', 'expected' => 'City name',                          'message' => 'Home city is required'],
                ['field' => 'owners.homeAddress.state',        'fix_type' => 'state_code', 'expected' => '2-letter US state code (e.g. NY, CA)', 'message' => 'Home state is required'],
                ['field' => 'owners.homeAddress.zipCode',      'fix_type' => 'zip',      'expected' => '5-digit ZIP code (e.g. 10001)',      'message' => 'Home ZIP code is required'],
            ],
        ];

        foreach ($errors as $error) {
            $field = $error['field'] ?? '';

            // Strip numeric indices for matching
            $normalised = implode('.', array_values(array_filter(
                explode('.', $field),
                fn($p) => !is_numeric($p)
            )));

            if (isset($compounds[$normalised]) && ($error['fix_type'] ?? '') === 'required') {
                // Replace compound field with individual sub-field errors
                foreach ($compounds[$normalised] as $sub) {
                    $expanded[] = array_merge($error, $sub, ['raw_message' => $error['raw_message'] ?? $error['message'] ?? '']);
                }
            } else {
                $expanded[] = $error;
            }
        }

        return $expanded;
    }
}
