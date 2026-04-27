<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CrmLeadDuplicateCheckService
{
    protected string $conn;
    protected ?int $excludeLeadId = null;

    public function __construct(int $clientId)
    {
        $this->conn = 'mysql_' . $clientId;
    }

    public static function forClient(int $clientId): self
    {
        return new self($clientId);
    }

    /**
     * Exclude a lead ID from duplicate checks (used for updates — skip self).
     */
    public function excluding(?int $leadId): self
    {
        $this->excludeLeadId = $leadId;
        return $this;
    }

    /**
     * Check for duplicate leads by phone, email, and business name.
     *
     * @param array $fields Key-value pairs (field_key => value) to check
     * @return array Validation-style errors array, empty if no duplicates
     */
    public function check(array $fields): array
    {
        $errors = [];

        // Phone check
        $phone = $this->extractField($fields, ['phone_number', 'mobile', 'phone', 'cell_phone']);
        if ($phone !== null) {
            $normalized = $this->normalizePhone($phone);
            if (strlen($normalized) >= 7) {
                $match = $this->findByEav($normalized, ['phone_number', 'mobile', 'phone', 'cell_phone'], 'phone');
                if ($match) {
                    $errors['phone_number'] = ["A lead with this phone number already exists (Lead #{$match->lead_id})."];
                }
            }
        }

        // Email check
        $email = $this->extractField($fields, ['email', 'email_address']);
        if ($email !== null) {
            $normalized = $this->normalizeEmail($email);
            if (strlen($normalized) >= 3 && str_contains($normalized, '@')) {
                $match = $this->findByEav($normalized, ['email', 'email_address'], 'email');
                if ($match) {
                    $errors['email'] = ["A lead with this email already exists (Lead #{$match->lead_id})."];
                }
            }
        }

        // Business name check
        $business = $this->extractField($fields, ['business_name', 'company_name', 'legal_name', 'dba']);
        if ($business !== null) {
            $normalized = $this->normalizeBusiness($business);
            if (strlen($normalized) >= 2) {
                $match = $this->findByEav($normalized, ['business_name', 'company_name', 'legal_name', 'dba'], 'business');
                if ($match) {
                    $errors['business_name'] = ["A lead with this business name already exists (Lead #{$match->lead_id})."];
                }
            }
        }

        return $errors;
    }

    /**
     * Check only fields that have actually changed from their current stored values.
     * Use this for update paths so unchanged fields don't trigger false positives.
     *
     * @param int   $leadId  The lead being updated
     * @param array $fields  All incoming fields (key => value)
     * @return array Validation-style errors array, empty if no duplicates
     */
    public function checkChanged(int $leadId, array $fields): array
    {
        // Load current EAV values for identity fields
        $identityKeys = ['phone_number', 'mobile', 'phone', 'cell_phone', 'email', 'email_address', 'business_name', 'company_name', 'legal_name', 'dba'];
        $current = DB::connection($this->conn)->table('crm_lead_values')
            ->where('lead_id', $leadId)
            ->whereIn('field_key', $identityKeys)
            ->pluck('field_value', 'field_key')
            ->toArray();

        // Build a filtered array containing only fields whose normalized value actually changed
        $changed = [];
        $phoneKeys    = ['phone_number', 'mobile', 'phone', 'cell_phone'];
        $emailKeys    = ['email', 'email_address'];
        $businessKeys = ['business_name', 'company_name', 'legal_name', 'dba'];

        foreach ($fields as $key => $value) {
            if (!in_array($key, $identityKeys)) continue;
            $val = trim((string) $value);
            if ($val === '') continue;

            $oldVal = $current[$key] ?? '';

            if (in_array($key, $phoneKeys)) {
                if ($this->normalizePhone($val) === $this->normalizePhone($oldVal)) continue;
            } elseif (in_array($key, $emailKeys)) {
                if ($this->normalizeEmail($val) === $this->normalizeEmail($oldVal)) continue;
            } elseif (in_array($key, $businessKeys)) {
                if ($this->normalizeBusiness($val) === $this->normalizeBusiness($oldVal)) continue;
            }

            $changed[$key] = $val;
        }

        if (empty($changed)) {
            return [];
        }

        return $this->excluding($leadId)->check($changed);
    }

    /**
     * Extract the first non-empty value from fields matching any of the given keys.
     */
    protected function extractField(array $fields, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (isset($fields[$key]) && trim((string) $fields[$key]) !== '') {
                return trim((string) $fields[$key]);
            }
        }
        return null;
    }

    /**
     * Find a matching lead in crm_lead_values by normalized value.
     */
    protected function findByEav(string $normalizedValue, array $fieldKeys, string $type): ?object
    {
        if ($type === 'phone') {
            return $this->findPhoneMatch($normalizedValue, $fieldKeys);
        }

        if ($type === 'business') {
            return $this->findBusinessMatch($normalizedValue, $fieldKeys);
        }

        // Email — exact match on lowercased/trimmed
        $query = DB::connection($this->conn)->table('crm_lead_values')
            ->whereIn('field_key', $fieldKeys)
            ->whereRaw('LOWER(TRIM(field_value)) = ?', [$normalizedValue]);

        if ($this->excludeLeadId) {
            $query->where('lead_id', '!=', $this->excludeLeadId);
        }

        return $query->select('lead_id', 'field_key', 'field_value')->first();
    }

    /**
     * Phone matching: fetch candidates and normalize in PHP.
     */
    protected function findPhoneMatch(string $normalizedDigits, array $fieldKeys): ?object
    {
        $query = DB::connection($this->conn)->table('crm_lead_values')
            ->whereIn('field_key', $fieldKeys)
            ->where('field_value', '!=', '')
            ->whereNotNull('field_value')
            ->whereRaw('LENGTH(field_value) >= 7');

        if ($this->excludeLeadId) {
            $query->where('lead_id', '!=', $this->excludeLeadId);
        }

        // Narrow down candidates using last 7 digits (covers most formats)
        $last7 = substr($normalizedDigits, -7);
        $query->whereRaw('field_value LIKE ?', ['%' . $last7 . '%']);

        $candidates = $query->select('lead_id', 'field_key', 'field_value')->limit(50)->get();

        foreach ($candidates as $row) {
            if ($this->normalizePhone($row->field_value) === $normalizedDigits) {
                return $row;
            }
        }

        return null;
    }

    /**
     * Business name matching: fetch candidates by LOWER(TRIM) and compare normalized in PHP.
     */
    protected function findBusinessMatch(string $normalizedName, array $fieldKeys): ?object
    {
        $query = DB::connection($this->conn)->table('crm_lead_values')
            ->whereIn('field_key', $fieldKeys)
            ->where('field_value', '!=', '')
            ->whereNotNull('field_value');

        if ($this->excludeLeadId) {
            $query->where('lead_id', '!=', $this->excludeLeadId);
        }

        $candidates = $query->select('lead_id', 'field_key', 'field_value')->limit(500)->get();

        foreach ($candidates as $row) {
            if ($this->normalizeBusiness($row->field_value) === $normalizedName) {
                return $row;
            }
        }

        return null;
    }

    /**
     * Strip non-digits, remove leading 1 for 11-digit NANP numbers.
     */
    public function normalizePhone(string $phone): string
    {
        $digits = preg_replace('/\D/', '', $phone);
        if (strlen($digits) === 11 && $digits[0] === '1') {
            $digits = substr($digits, 1);
        }
        return $digits;
    }

    /**
     * Trim and lowercase email.
     */
    public function normalizeEmail(string $email): string
    {
        return strtolower(trim($email));
    }

    /**
     * Trim, lowercase, strip common business suffixes (LLC, Inc, Corp, etc.).
     */
    public function normalizeBusiness(string $name): string
    {
        $name = strtolower(trim($name));
        $name = preg_replace('/\s*(,\s*)?(llc|inc|corp|corporation|ltd|co|company)\.?\s*$/i', '', $name);
        return trim($name);
    }
}
