<?php

namespace App\Services;

/**
 * ApiErrorMapper
 *
 * Reusable helper that converts raw lender API errors into a clean,
 * UI-friendly array of { label, field, message, fix_type, expected }.
 *
 * Usage:
 *   $mapped = ApiErrorMapper::map($httpStatusCode, $responseBody);
 *   // or from pre-parsed fix_suggestions:
 *   $mapped = ApiErrorMapper::fromFixSuggestions($fixSuggestions);
 *
 * Output:
 *   [
 *     ['label' => 'Owner Date of Birth', 'field' => 'owner_dob', 'message' => '...', 'fix_type' => 'date'],
 *     ['label' => 'Business Phone',      'field' => 'business_phone', 'message' => '...', 'fix_type' => 'phone'],
 *   ]
 */
class ApiErrorMapper
{
    // ── Human-readable labels for common API/CRM field keys ─────────────────
    // Keys are CRM field_keys (snake_case). Values are display labels.
    private const FIELD_LABELS = [
        // Owner / personal
        'first_name'        => 'First Name',
        'last_name'         => 'Last Name',
        'email'             => 'Email Address',
        'phone'             => 'Phone Number',
        'cell_phone'        => 'Cell Phone',
        'date_of_birth'     => 'Owner Date of Birth',
        'owner_dob'         => 'Owner Date of Birth',
        'dob'               => 'Date of Birth',
        'ssn'               => 'Social Security Number',
        'home_address'      => 'Home Address',
        'home_city'         => 'Home City',
        'home_state'        => 'Home State',
        'home_zip'          => 'Home ZIP Code',
        'zip_code'          => 'ZIP Code',
        'address'           => 'Address',
        'city'              => 'City',
        'state'             => 'State',

        // Business
        'business_name'     => 'Business Name',
        'legal_name'        => 'Legal Business Name',
        'company_name'      => 'Company Name',
        'business_phone'    => 'Business Phone',
        'business_email'    => 'Business Email',
        'business_address'  => 'Business Address',
        'business_city'     => 'Business City',
        'business_state'    => 'Business State',
        'business_zip'      => 'Business ZIP Code',
        'ein'               => 'EIN / Tax ID',
        'fein'              => 'Federal EIN',
        'tax_id'            => 'Tax ID',

        // Financial
        'amount_requested'      => 'Amount Requested',
        'monthly_revenue'       => 'Monthly Revenue',
        'annual_revenue'        => 'Annual Revenue',
        'avg_monthly_sales'     => 'Avg. Monthly Sales',
        'self_reported_revenue' => 'Monthly Revenue',
        'average_balance'       => 'Avg. Bank Balance',
        'credit_score'          => 'Credit Score',
        'bank_name'             => 'Bank Name',
        'routing_number'        => 'Routing Number',
        'account_number'        => 'Account Number',

        // Business details
        'business_start_date'   => 'Business Start Date',
        'industry'              => 'Industry',
        'business_type'         => 'Business Type',
        'entity_type'           => 'Entity Type',
        'ownership_percentage'       => 'Ownership Percentage',
        'total_ownership_percentage' => 'Ownership Percentage',
        'years_in_business'     => 'Years in Business',
        'number_of_employees'   => 'Number of Employees',
        'website'               => 'Website URL',

        // Common option_* fields (legacy CRM field keys)
        'option_34'    => 'Home State',
        'option_37'    => 'Home Address',
        'option_38'    => 'Business Phone',
        'option_39'    => 'Amount Requested',
        'option_44'    => 'SSN',
        'option_45'    => 'Business ZIP Code',
        'option_46'    => 'Home ZIP Code',
        'option_724'   => 'Business Address',
        'option_730'   => 'EIN / Tax ID',
        'option_731'   => 'Business Start Date',
        'option_733'   => 'Ownership Percentage',
        'option_749'   => 'Monthly Revenue',
        'option_750'   => 'Average Bank Balance',
        'phone_number' => 'Phone Number',
        'full_name'    => 'Full Name',
    ];

    // ── Public API ─────────────────────────────────────────────────────────────

    /**
     * Parse raw API response into mapped errors with labels + CRM field keys.
     *
     * @param  int         $statusCode    HTTP status code
     * @param  string|null $responseBody  Raw API response body
     * @param  array       $leadData      Optional EAV data for fix suggestions
     * @return array<int, array{label: string, field: string, message: string, fix_type: string, expected: string}>
     */
    public static function map(int $statusCode, ?string $responseBody, array $leadData = [], ?array $payloadMapping = null): array
    {
        // Strip "HTTP 400: " prefix if present (stored api_error format)
        if ($responseBody && preg_match('/^HTTP\s+(\d{3}):\s*(.+)$/s', $responseBody, $m)) {
            $statusCode   = (int) $m[1];
            $responseBody = $m[2];
        }

        $parser    = new ErrorParserService();
        $errors    = $parser->parse($statusCode, $responseBody);

        if (empty($errors)) {
            return [];
        }

        // Enrich with CRM key mapping + fix suggestions
        if (!empty($leadData)) {
            $fixer  = new FixSuggestionService();
            $errors = $fixer->suggest($errors, $leadData);
        }

        return self::normalize($errors, $payloadMapping);
    }

    /**
     * Convert pre-enriched fix_suggestions (from crm_lender_api_logs) into the clean UI format.
     *
     * @param  array $fixSuggestions  Output of FixSuggestionService::suggest()
     * @return array<int, array{label: string, field: string, message: string, fix_type: string, expected: string}>
     */
    /**
     * @param  array       $fixSuggestions  Output of FixSuggestionService::suggest()
     * @param  array|null  $payloadMapping  Lender's payload_mapping (CRM key → API path)
     */
    public static function fromFixSuggestions(array $fixSuggestions, ?array $payloadMapping = null): array
    {
        return self::normalize($fixSuggestions, $payloadMapping);
    }

    // ── Internals ──────────────────────────────────────────────────────────────

    /**
     * Build a reverse lookup: API dot-path → CRM field key from payload_mapping.
     *
     * payload_mapping is { crmKey: apiPath | [apiPath, ...] }
     * We invert it to { apiPath: crmKey } so we can look up the real CRM key from an error's API path.
     */
    private static function buildReverseMap(?array $payloadMapping): array
    {
        if (empty($payloadMapping)) {
            return [];
        }
        $reverse = [];
        foreach ($payloadMapping as $crmKey => $apiPaths) {
            if (str_starts_with($crmKey, '=')) continue; // skip static values
            $paths = is_array($apiPaths) ? $apiPaths : [$apiPaths];
            foreach ($paths as $path) {
                $reverse[$path] = $crmKey;
                // Also map the last segment (e.g. "ssn" from "owners.0.ssn")
                $parts = explode('.', $path);
                $last = end($parts);
                if (!isset($reverse[$last])) {
                    $reverse[$last] = $crmKey;
                }
                // Also map without numeric indices: "owners.ssn"
                $noNums = implode('.', array_filter($parts, fn($p) => !is_numeric($p)));
                if (!isset($reverse[$noNums])) {
                    $reverse[$noNums] = $crmKey;
                }
            }
        }
        return $reverse;
    }

    /**
     * Normalize an array of parsed/enriched errors into the clean output format.
     */
    private static function normalize(array $errors, ?array $payloadMapping = null): array
    {
        $reverseMap = self::buildReverseMap($payloadMapping);
        $mapped = [];

        foreach ($errors as $error) {
            $crmKey   = $error['crm_key'] ?? '';
            $apiField = $error['field']   ?? '';

            // 1. Try reverse lookup from payload_mapping (most accurate)
            if (!empty($reverseMap) && $apiField !== '') {
                // Try full path first, then last segment
                if (isset($reverseMap[$apiField])) {
                    $crmKey = $reverseMap[$apiField];
                } else {
                    $parts = explode('.', $apiField);
                    $last  = end($parts);
                    if (isset($reverseMap[$last])) {
                        $crmKey = $reverseMap[$last];
                    }
                    // Try without numeric indices
                    if ($crmKey === '' || $crmKey === $error['crm_key']) {
                        $noNums = implode('.', array_filter($parts, fn($p) => !is_numeric($p)));
                        if (isset($reverseMap[$noNums])) {
                            $crmKey = $reverseMap[$noNums];
                        }
                    }
                }
            }

            // 2. Fall back to generic apiFieldToCrmKey mapper
            if ($crmKey === '' && $apiField !== '') {
                $crmKey = self::apiFieldToCrmKey($apiField);
            }

            // 3. If crmKey is a generic semantic name (not option_*), check if
            //    any payload_mapping CRM key maps to the same API path.
            //    e.g. totalOwnershipPercentage → ownership_percentage → option_733
            if ($crmKey !== '' && !str_starts_with($crmKey, 'option_') && !empty($payloadMapping)) {
                foreach ($payloadMapping as $pmCrmKey => $pmApiPaths) {
                    if (str_starts_with($pmCrmKey, '=')) continue;
                    $paths = is_array($pmApiPaths) ? $pmApiPaths : [$pmApiPaths];
                    foreach ($paths as $path) {
                        $parts = explode('.', $path);
                        $lastSegment = end($parts);
                        // Check if the API path's last segment maps to the same generic key
                        $genericKey = self::apiFieldToCrmKey($lastSegment);
                        if ($genericKey === $crmKey) {
                            $crmKey = $pmCrmKey;
                            break 2;
                        }
                    }
                }
            }

            // Resolve human label: static map first, then fall back to auto-generated
            $label = self::FIELD_LABELS[$crmKey] ?? self::autoLabel($crmKey ?: $apiField);

            $mapped[] = [
                'label'    => $label,
                'field'    => $crmKey ?: $apiField,
                'message'  => $error['message']  ?? $error['raw_message'] ?? '',
                'fix_type' => $error['fix_type']  ?? 'unknown',
                'expected' => $error['expected']  ?? $error['suggestion'] ?? '',
            ];
        }

        return $mapped;
    }

    /**
     * Map an API dot-notation field path to a CRM EAV field_key.
     * Mirrors FixSuggestionService::toCrmKey() for standalone use.
     */
    private static function apiFieldToCrmKey(string $path): string
    {
        if ($path === '') {
            return '';
        }

        // Strip numeric index segments
        $parts = array_values(array_filter(
            explode('.', $path),
            fn($p) => !is_numeric($p)
        ));

        if (empty($parts)) {
            return $path;
        }

        // Full-path map (context-aware — checks parent.child)
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
            'federalId'                => 'ein',
            'dob'                      => 'date_of_birth',
            'dateOfBirth'              => 'date_of_birth',
            'businessName'             => 'business_name',
            'legalName'                => 'legal_name',
            'address'                  => 'address',
            'streetAddress'            => 'address',
            'addressLine1'             => 'address',
            'homeAddress'              => 'home_address',
            'city'                     => 'city',
            'businessCity'             => 'business_city',
            'businessInceptionDate'    => 'business_start_date',
            'businessStartDate'        => 'business_start_date',
            'bizStartDate'             => 'business_start_date',
            'dateEstablished'          => 'business_start_date',
            'startDate'                => 'business_start_date',
            'homePhone'                => 'phone_number',
            'name'                     => 'company_name',
            'revenue'                  => 'monthly_revenue',
            'selfReported'             => 'monthly_revenue',
            'selfReportedRevenue'      => 'monthly_revenue',
            'averageBalance'           => 'avg_monthly_sales',
            'ownershipPercentage'      => 'ownership_percentage',
            'totalOwnershipPercentage' => 'ownership_percentage',
            'socialSecurityNumber'     => 'ssn',
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

    /**
     * Auto-generate a human-readable label from a dot-notation API field path.
     *
     *   "owners.0.homeAddress.state" → "Owner 1 Home Address State"
     *   "businessPhone"              → "Business Phone"
     */
    private static function autoLabel(string $path): string
    {
        if ($path === '') {
            return 'Unknown Field';
        }

        // Known abbreviation replacements (before splitting)
        static $abbr = [
            'taxID'   => 'Tax ID',
            'taxId'   => 'Tax ID',
            'ssn'     => 'SSN',
            'SSN'     => 'SSN',
            'ein'     => 'EIN',
            'EIN'     => 'EIN',
            'fein'    => 'FEIN',
            'FEIN'    => 'FEIN',
            'dob'     => 'Date of Birth',
            'DOB'     => 'Date of Birth',
            'dba'     => 'DBA',
            'DBA'     => 'DBA',
            'naics'   => 'NAICS',
            'NAICS'   => 'NAICS',
            'zipCode' => 'ZIP Code',
        ];

        $parts  = explode('.', $path);
        $labels = [];

        foreach ($parts as $part) {
            if (is_numeric($part)) {
                if ($labels) {
                    $labels[count($labels) - 1] .= ' ' . ((int) $part + 1);
                }
                continue;
            }
            // Check abbreviation map first
            if (isset($abbr[$part])) {
                $labels[] = $abbr[$part];
                continue;
            }
            // Insert space before uppercase runs, but keep acronyms together
            // e.g., "businessInceptionDate" → "business Inception Date"
            $spaced = preg_replace('/([a-z])([A-Z])/', '$1 $2', $part) ?? $part;
            $spaced = preg_replace('/([A-Z]+)([A-Z][a-z])/', '$1 $2', $spaced) ?? $spaced;
            $labels[] = trim(ucwords(str_replace(['_', '-'], ' ', $spaced)));
        }

        return implode(' ', $labels) ?: 'Unknown Field';
    }
}
