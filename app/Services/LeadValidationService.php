<?php

namespace App\Services;

use Illuminate\Support\Facades\Validator;

/**
 * LeadValidationService
 *
 * Converts JSON validation_rules stored in crm_labels into Laravel
 * validation rule strings, and runs validation against submitted EAV
 * field values.
 *
 * Rule JSON object format:
 *   { "rule": "required" }
 *   { "rule": "digits",         "value": 9 }
 *   { "rule": "digits_between", "value": 5, "value2": 17 }
 *   { "rule": "regex",          "value": "/^[a-zA-Z\\s]+$/" }
 *
 * To add a new rule type:
 *   1. Add a case to buildRuleString() below
 *   2. Add the matching entry to RULE_DEFINITIONS in CrmLeadFields.tsx
 */
class LeadValidationService
{
    /**
     * Build a Laravel validation rules array from an array of crm_labels rows.
     *
     * For each label that has a non-empty validation_rules JSON column,
     * the stored rule objects are converted to Laravel rule strings.
     * Labels without validation_rules fall through to a nullable rule so
     * they don't cause hard 422 errors on optional fields.
     *
     * @param  array $labels  Array of stdClass rows from crm_labels
     * @return array<string, string[]>  ['field_key' => ['required', 'numeric', …]]
     */
    public function buildRules(array $labels): array
    {
        $rules = [];

        foreach ($labels as $label) {
            $key            = $label->field_key ?? null;
            $validationJson = $label->validation_rules ?? null;

            if (!$key) {
                continue;
            }

            if ($validationJson) {
                $objects = is_array($validationJson)
                    ? $validationJson
                    : json_decode((string) $validationJson, true);

                if (is_array($objects) && count($objects) > 0) {
                    $built = [];
                    foreach ($objects as $obj) {
                        if (is_array($obj)) {
                            $str = $this->buildRuleString($obj);
                            if ($str !== null) {
                                $built[] = $str;
                            }
                        }
                    }
                    if (!empty($built)) {
                        $rules[$key] = $built;
                        continue;
                    }
                }
            }

            // Fallback: honour the basic required flag only
            if (!empty($label->required)) {
                $rules[$key] = ['required'];
            }
        }

        return $rules;
    }

    /**
     * Validate EAV field values against the built rules array.
     *
     * Only validates keys that are (a) present in $rules AND (b) present
     * in $input — missing optional fields are silently skipped.
     *
     * @param  array  $input   EAV key → value pairs from the request
     * @param  array  $rules   Output of buildRules()
     * @param  array  $labels  Original label rows for friendly attribute names
     * @return array  ['field_key' => ['error message', …]] or empty on success
     */
    public function validate(array $input, array $rules, array $labels = []): array
    {
        if (empty($rules)) {
            return [];
        }

        // Only validate keys that are both defined and submitted
        $filteredRules = array_intersect_key($rules, $input);
        $filteredInput = array_intersect_key($input, $filteredRules);

        if (empty($filteredRules)) {
            return [];
        }

        // Friendly attribute names for error messages
        $attributes = [];
        foreach ($labels as $label) {
            if (!empty($label->field_key) && !empty($label->label_name)) {
                $attributes[$label->field_key] = $label->label_name;
            }
        }

        $validator = Validator::make($filteredInput, $filteredRules, [], $attributes);

        return $validator->fails() ? $validator->errors()->toArray() : [];
    }

    /**
     * Convert a single rule object to a Laravel rule string.
     *
     * Returns null for unrecognized or incomplete rules so they are silently
     * skipped rather than causing a runtime error.
     *
     * @param  array $obj  Rule object, e.g. ['rule' => 'digits', 'value' => 9]
     * @return string|null
     */
    public function buildRuleString(array $obj): ?string
    {
        $rule = strtolower(trim((string) ($obj['rule'] ?? '')));
        $val  = $obj['value']  ?? null;
        $val2 = $obj['value2'] ?? null;

        return match ($rule) {
            // ── No-value rules ────────────────────────────────────────────────
            'required'    => 'required',
            'nullable'    => 'nullable',
            'numeric'     => 'numeric',
            'integer'     => 'integer',
            'string'      => 'string',
            'email'       => 'email',
            'url'         => 'url',
            'date'        => 'date',
            'alpha'       => 'alpha',
            'alpha_num'   => 'alpha_num',
            // alpha_spaces: not a native Laravel rule — map to regex
            'alpha_spaces' => 'regex:/^[a-zA-Z\s]+$/',

            // ── Single-value rules ────────────────────────────────────────────
            'min'       => $val !== null ? "min:{$val}"       : null,
            'max'       => $val !== null ? "max:{$val}"       : null,
            'digits'    => $val !== null ? "digits:{$val}"    : null,
            'size'      => $val !== null ? "size:{$val}"      : null,
            'before'    => $val !== null ? "before:{$val}"    : null,
            'after'     => $val !== null ? "after:{$val}"     : null,
            'in'        => $val !== null ? "in:{$val}"        : null,
            // min_value / max_value are friendly aliases for min/max
            'min_value' => $val !== null ? "min:{$val}"       : null,
            'max_value' => $val !== null ? "max:{$val}"       : null,
            // regex: stored with delimiters, passed as-is
            'regex'     => $val !== null ? "regex:{$val}"     : null,

            // ── Two-value rules ───────────────────────────────────────────────
            'digits_between' => ($val !== null && $val2 !== null)
                ? "digits_between:{$val},{$val2}"
                : null,
            'between' => ($val !== null && $val2 !== null)
                ? "between:{$val},{$val2}"
                : null,

            default => null,
        };
    }
}
