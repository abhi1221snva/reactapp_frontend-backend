<?php

namespace App\Services;

/**
 * FieldValidationService — centralized field_type → validation logic.
 *
 * Single source of truth for all CRM dynamic field validation.
 * Used by LeadController::buildEavValidation() and can be reused
 * in any controller that processes EAV field data.
 *
 * Field types supported:
 *   text, textarea, text_area, email, phone_number, phone,
 *   number, date, dropdown, select, select_option,
 *   percentage, ssn, file, radio, checkbox
 */
class FieldValidationService
{
    /**
     * Sanitize a raw value before validation.
     * Mutates $input[$key] for phone fields (strips non-numeric chars).
     *
     * @param  mixed   $raw     Original value from request
     * @param  string  $type    Normalized field_type
     * @param  array   &$input  Full input array (mutated for phone)
     * @param  string  $key     Field key
     * @return mixed            Sanitized value
     */
    public function sanitize(mixed $raw, string $type, array &$input, string $key): mixed
    {
        if (in_array($type, ['phone_number', 'phone'], true)) {
            $clean       = preg_replace('/[^0-9]/', '', (string) $raw);
            $input[$key] = $clean;
            return $clean;
        }

        // SSN: strip dashes/spaces — store digits only
        if ($type === 'ssn' || preg_match('/\bssn\b/i', $key)) {
            $clean       = preg_replace('/[^0-9]/', '', (string) $raw);
            $input[$key] = $clean;
            return $clean;
        }

        return is_string($raw) ? trim($raw) : $raw;
    }

    /**
     * Validate a single field value.
     *
     * @param  mixed       $value      Sanitized value (already trimmed / stripped)
     * @param  string      $type       Normalized field_type (lowercased)
     * @param  string      $label      Human-readable label name (for error messages)
     * @param  array|null  $options    Allowed option values (for dropdown/select/radio)
     * @return string|null             Error message, or null if valid
     */
    public function validate(mixed $value, string $type, string $label, ?array $options = null): ?string
    {
        switch ($type) {
            // ── Phone ──────────────────────────────────────────────────────────
            case 'phone_number':
            case 'phone':
                if (!preg_match('/^\d{10}$/', (string) $value)) {
                    return "{$label} must be exactly 10 digits.";
                }
                break;

            // ── Email ──────────────────────────────────────────────────────────
            case 'email':
                if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
                    return "{$label} must be a valid email address.";
                }
                break;

            // ── Number ─────────────────────────────────────────────────────────
            case 'number':
                if (!is_numeric($value)) {
                    return "{$label} must be a numeric value.";
                }
                break;

            // ── Percentage ─────────────────────────────────────────────────────
            case 'percentage':
                if (!is_numeric($value)) {
                    return "{$label} must be a numeric value.";
                }
                $num = (float) $value;
                if ($num < 0 || $num > 100) {
                    return "{$label} must be between 0 and 100.";
                }
                break;

            // ── Date ───────────────────────────────────────────────────────────
            case 'date':
                if (!date_create((string) $value)) {
                    return "{$label} must be a valid date.";
                }
                break;

            // ── Text / Textarea ────────────────────────────────────────────────
            case 'text':
            case 'textarea':
            case 'text_area':
                if (mb_strlen((string) $value) > 500) {
                    return "{$label} must not exceed 500 characters.";
                }
                break;

            // ── Dropdown / Select ──────────────────────────────────────────────
            case 'dropdown':
            case 'select':
            case 'select_option':
                if (!empty($options)) {
                    $opts = array_map('strval', $options);
                    if (!in_array((string) $value, $opts, true)) {
                        return "{$label} must be a valid option.";
                    }
                }
                break;

            // ── Radio ──────────────────────────────────────────────────────────
            case 'radio':
                if (!empty($options)) {
                    $opts = array_map('strval', $options);
                    if (!in_array((string) $value, $opts, true)) {
                        return "{$label} must be a valid option.";
                    }
                }
                break;

            // ── SSN ────────────────────────────────────────────────────────────
            case 'ssn':
                $digits = preg_replace('/[^0-9]/', '', (string) $value);
                if (strlen($digits) !== 9) {
                    return "{$label} must be 9 digits (XXX-XX-XXXX).";
                }
                break;

            // ── File ───────────────────────────────────────────────────────────
            // File fields are validated via multipart/form-data — skip string validation.
            case 'file':
                break;

            // ── Checkbox ───────────────────────────────────────────────────────
            // Any truthy/falsy value is acceptable.
            case 'checkbox':
                break;
        }

        return null;
    }

    /**
     * Decode the JSON options string stored in crm_labels.options.
     *
     * @param  string|null $raw  JSON string or pipe-delimited string
     * @return array|null
     */
    public function decodeOptions(?string $raw): ?array
    {
        if (empty($raw)) {
            return null;
        }

        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        // Fallback: pipe-delimited
        $parts = array_filter(array_map('trim', explode('|', $raw)));
        return !empty($parts) ? array_values($parts) : null;
    }
}
