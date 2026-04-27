<?php

namespace App\Services;

/**
 * ValidationSuggestionService
 *
 * Provides automatic validation rule suggestions based on field_key,
 * label_name, and field_type. Used by the CRM field builder to pre-fill
 * validation rules when a user creates or names a new field.
 *
 * Rule object format:
 *   { "rule": "digits", "value": 9 }
 *   { "rule": "digits_between", "value": 5, "value2": 17 }
 *   { "rule": "regex", "value": "/^[a-zA-Z\\s]+$/" }
 *
 * NOTE: "required" is intentionally excluded from suggestions — required status
 * is controlled by the label's `required` column + `apply_to` scope, not inline rules.
 *
 * To add new keyword mappings — append entries to $keywordMap.
 * To add new rule types — add cases to LeadValidationService::buildRuleString().
 */
class ValidationSuggestionService
{
    /**
     * Keyword → rules map.
     *
     * Keys are normalized lowercase strings (underscores, no spaces).
     * Values are arrays of rule objects.
     * More specific / longer keywords take priority via sortedByLength().
     *
     * @var array<string, array<int, array<string, mixed>>>
     */
    private array $keywordMap = [
        // ── SSN / Social Security ────────────────────────────────────────────
        // Only digits:9 — no 'numeric' rule because the UI auto-formats with dashes (XXX-XX-XXXX)
        'social_security_number' => [['rule' => 'digits', 'value' => 9]],
        'social_security'        => [['rule' => 'digits', 'value' => 9]],
        'ssn'                    => [['rule' => 'digits', 'value' => 9]],

        // ── Phone / Mobile ───────────────────────────────────────────────────
        // Only digits:10 — no 'numeric' rule because the UI may format with parens/dashes
        // and the sanitizer strips non-digit chars before validation.
        'phone_number'           => [['rule' => 'digits', 'value' => 10]],
        'mobile_number'          => [['rule' => 'digits', 'value' => 10]],
        'phone'                  => [['rule' => 'digits', 'value' => 10]],
        'mobile'                 => [['rule' => 'digits', 'value' => 10]],
        'cell'                   => [['rule' => 'digits', 'value' => 10]],
        'fax'                    => [['rule' => 'digits', 'value' => 10]],

        // ── Email ────────────────────────────────────────────────────────────
        'email_address'          => [['rule' => 'email'], ['rule' => 'max', 'value' => 100]],
        'email'                  => [['rule' => 'email'], ['rule' => 'max', 'value' => 100]],

        // ── Name fields ──────────────────────────────────────────────────────
        'first_name'             => [['rule' => 'alpha_spaces'], ['rule' => 'min', 'value' => 2], ['rule' => 'max', 'value' => 50]],
        'last_name'              => [['rule' => 'alpha_spaces'], ['rule' => 'min', 'value' => 2], ['rule' => 'max', 'value' => 50]],
        'middle_name'            => [['rule' => 'alpha_spaces'], ['rule' => 'max', 'value' => 50]],
        'full_name'              => [['rule' => 'alpha_spaces'], ['rule' => 'min', 'value' => 2], ['rule' => 'max', 'value' => 100]],
        'owner_name'             => [['rule' => 'alpha_spaces'], ['rule' => 'min', 'value' => 2], ['rule' => 'max', 'value' => 100]],

        // ── Zip / Postal ─────────────────────────────────────────────────────
        'zip_code'               => [['rule' => 'numeric'], ['rule' => 'digits_between', 'value' => 5, 'value2' => 10]],
        'postal_code'            => [['rule' => 'numeric'], ['rule' => 'digits_between', 'value' => 5, 'value2' => 10]],
        'zip'                    => [['rule' => 'numeric'], ['rule' => 'digits_between', 'value' => 5, 'value2' => 10]],

        // ── Date of Birth ────────────────────────────────────────────────────
        'date_of_birth'          => [['rule' => 'date'], ['rule' => 'before', 'value' => 'today']],
        'birth_date'             => [['rule' => 'date'], ['rule' => 'before', 'value' => 'today']],
        'dob'                    => [['rule' => 'date'], ['rule' => 'before', 'value' => 'today']],

        // ── EIN / Tax ID ─────────────────────────────────────────────────────
        'tax_id_number'          => [['rule' => 'numeric'], ['rule' => 'digits', 'value' => 9]],
        'employer_id'            => [['rule' => 'numeric'], ['rule' => 'digits', 'value' => 9]],
        'tax_id'                 => [['rule' => 'numeric'], ['rule' => 'digits', 'value' => 9]],
        'ein'                    => [['rule' => 'numeric'], ['rule' => 'digits', 'value' => 9]],

        // ── Bank routing / account ───────────────────────────────────────────
        'routing_number'         => [['rule' => 'numeric'], ['rule' => 'digits', 'value' => 9]],
        'account_number'         => [['rule' => 'numeric'], ['rule' => 'digits_between', 'value' => 5, 'value2' => 17]],
        'bank_account'           => [['rule' => 'numeric'], ['rule' => 'digits_between', 'value' => 5, 'value2' => 17]],

        // ── Amounts / Revenue ────────────────────────────────────────────────
        'annual_revenue'         => [['rule' => 'numeric'], ['rule' => 'min_value', 'value' => 0]],
        'monthly_revenue'        => [['rule' => 'numeric'], ['rule' => 'min_value', 'value' => 0]],
        'requested_amount'       => [['rule' => 'numeric'], ['rule' => 'min_value', 'value' => 1]],
        'loan_amount'            => [['rule' => 'numeric'], ['rule' => 'min_value', 'value' => 1]],
        'average_balance'        => [['rule' => 'numeric'], ['rule' => 'min_value', 'value' => 0]],
        'amount'                 => [['rule' => 'numeric'], ['rule' => 'min_value', 'value' => 0]],
        'revenue'                => [['rule' => 'numeric'], ['rule' => 'min_value', 'value' => 0]],
        'income'                 => [['rule' => 'numeric'], ['rule' => 'min_value', 'value' => 0]],
        'balance'                => [['rule' => 'numeric']],
        'salary'                 => [['rule' => 'numeric'], ['rule' => 'min_value', 'value' => 0]],

        // ── Website / URL ────────────────────────────────────────────────────
        'website_url'            => [['rule' => 'url'], ['rule' => 'max', 'value' => 255]],
        'website'                => [['rule' => 'url'], ['rule' => 'max', 'value' => 255]],
        'url'                    => [['rule' => 'url'], ['rule' => 'max', 'value' => 255]],

        // ── Age ──────────────────────────────────────────────────────────────
        'age'                    => [['rule' => 'numeric'], ['rule' => 'min_value', 'value' => 18], ['rule' => 'max_value', 'value' => 120]],

        // ── Years ────────────────────────────────────────────────────────────
        'years_in_business'      => [['rule' => 'numeric'], ['rule' => 'min_value', 'value' => 0], ['rule' => 'max_value', 'value' => 200]],
        'years_at_address'       => [['rule' => 'numeric'], ['rule' => 'min_value', 'value' => 0]],

        // ── Business / Company ───────────────────────────────────────────────
        'business_name'          => [['rule' => 'min', 'value' => 2], ['rule' => 'max', 'value' => 200]],
        'company_name'           => [['rule' => 'min', 'value' => 2], ['rule' => 'max', 'value' => 200]],
        'dba'                    => [['rule' => 'min', 'value' => 2], ['rule' => 'max', 'value' => 200]],
    ];

    /**
     * Suggest validation rules for a field.
     *
     * Matching priority:
     *  1. Exact match on normalised field_key
     *  2. Partial keyword match inside field_key (longer keywords win)
     *  3. Partial keyword match inside label_name (longer keywords win)
     *  4. Fallback based on field_type only
     *
     * @param  string $fieldKey   EAV field key  (e.g. "ssn", "owner_first_name")
     * @param  string $labelName  Display label  (e.g. "Social Security Number")
     * @param  string $fieldType  field_type     (e.g. "text", "email", "number")
     * @return array<int, array<string, mixed>>  Array of rule objects
     */
    public function suggest(string $fieldKey, string $labelName = '', string $fieldType = 'text'): array
    {
        $nKey   = strtolower(trim($fieldKey));
        $nLabel = strtolower(trim(preg_replace('/[^a-z0-9]+/i', '_', $labelName)));

        // 1. Exact match on field_key
        if (isset($this->keywordMap[$nKey])) {
            return $this->keywordMap[$nKey];
        }

        $sorted = $this->keywordsSortedByLength();

        // 2. Partial match inside field_key
        foreach ($sorted as $keyword) {
            if (str_contains($nKey, $keyword)) {
                return $this->keywordMap[$keyword];
            }
        }

        // 3. Partial match inside label_name
        foreach ($sorted as $keyword) {
            if (str_contains($nLabel, $keyword)) {
                return $this->keywordMap[$keyword];
            }
        }

        // 4. Fallback by field_type
        return $this->fallbackByType($fieldType);
    }

    // ──────────────────────────────────────────────────────────────────────────

    private function fallbackByType(string $fieldType): array
    {
        return match (strtolower($fieldType)) {
            'email'        => [['rule' => 'email'], ['rule' => 'max', 'value' => 255]],
            'phone_number' => [['rule' => 'digits', 'value' => 10]],
            'number'       => [['rule' => 'numeric']],
            'date'         => [['rule' => 'date']],
            'url'          => [['rule' => 'url']],
            default        => [['rule' => 'max', 'value' => 255]],
        };
    }

    private function keywordsSortedByLength(): array
    {
        $keys = array_keys($this->keywordMap);
        usort($keys, fn($a, $b) => strlen($b) <=> strlen($a));
        return $keys;
    }
}
