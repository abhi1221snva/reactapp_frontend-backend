<?php

namespace App\Services;

/**
 * ErrorParserService
 *
 * Converts raw lender API error responses (any vendor format) into a
 * normalised, structured array the frontend can act on.
 *
 * Completely lender-agnostic — no hardcoded vendor logic.
 *
 * Supported response shapes:
 *   • { "errorMessages": ["field.path message", ...] }         OnDeck / Credibly style
 *   • { "errors": { "field": ["msg", ...] } }                  Laravel validation style
 *   • { "errors": ["msg", ...] }                               Flat array style
 *   • { "message": "...", "errors": [...] }                    Generic REST style
 *   • { "detail": "..." }                                      RFC 7807 Problem Details
 *   • { "error": "...", "error_description": "..." }           OAuth2 error style
 *   • { "data": { "errors": [...] } }                          Nested wrapper style
 *   • Plain-text body fallback
 */
class ErrorParserService
{
    // ── Public API ─────────────────────────────────────────────────────────────

    /**
     * Parse an HTTP error response into an array of structured errors.
     *
     * @param  int         $statusCode   HTTP status code (400, 422, 500, …)
     * @param  string|null $responseBody Raw response body
     * @return array<int, array{
     *   field:        string,
     *   raw_message:  string,
     *   message:      string,
     *   fix_type:     string,
     *   expected:     string,
     *   path_parts:   string[],
     * }>
     */
    public function parse(int $statusCode, ?string $responseBody): array
    {
        if (empty($responseBody)) {
            return [];
        }

        $json = json_decode($responseBody, true);

        $rawMessages = $this->extractMessages($json, $responseBody);

        $errors = [];
        foreach ($rawMessages as $raw) {
            if (strlen(trim($raw)) < 3) {
                continue;
            }
            $parsed = $this->parseMessage($raw);
            if ($parsed !== null) {
                $errors[] = $parsed;
            }
        }

        return $errors;
    }

    // ── Message Extraction ─────────────────────────────────────────────────────

    /**
     * Walk the decoded JSON (or raw string) and collect all human-readable
     * error messages, regardless of the vendor's response shape.
     *
     * @param  array|null $json    Decoded JSON or null
     * @param  string     $raw     Original response body string
     * @return string[]
     */
    private function extractMessages(?array $json, string $raw): array
    {
        if ($json === null) {
            // Non-JSON body — treat the whole thing as one message
            return [trim($raw)];
        }

        $messages = [];

        // ── 1. errorMessages array (OnDeck / Credibly pattern) ───────────────
        if (isset($json['errorMessages']) && is_array($json['errorMessages'])) {
            foreach ($json['errorMessages'] as $m) {
                if (is_string($m) && $m !== '') {
                    $messages[] = $m;
                }
            }
            if ($messages) {
                return $messages;
            }
        }

        // ── 2. errors field (object or array) ────────────────────────────────
        if (isset($json['errors']) && is_array($json['errors'])) {
            if ($this->isAssoc($json['errors'])) {
                // Object: { "field": ["msg"] }
                foreach ($json['errors'] as $field => $msgs) {
                    $list = is_array($msgs) ? $msgs : [$msgs];
                    foreach ($list as $m) {
                        if (is_string($m) && $m !== '') {
                            // Prepend field path when message doesn't already contain it
                            $messages[] = str_contains(strtolower($m), strtolower((string) $field))
                                ? $m
                                : "{$field} {$m}";
                        }
                    }
                }
            } else {
                // Flat array: ["msg", "msg"]
                foreach ($json['errors'] as $item) {
                    if (is_string($item) && $item !== '') {
                        $messages[] = $item;
                    } elseif (is_array($item)) {
                        foreach ($item as $v) {
                            if (is_string($v) && $v !== '') {
                                $messages[] = $v;
                            }
                        }
                    }
                }
            }

            if ($messages) {
                return $messages;
            }
        }

        // ── 3. Top-level scalar keys ──────────────────────────────────────────
        foreach (['message', 'detail', 'error', 'error_description', 'description', 'title', 'msg'] as $key) {
            if (!empty($json[$key]) && is_string($json[$key])) {
                $messages[] = $json[$key];
            }
        }

        // ── 4. Nested wrappers: data / response / result ──────────────────────
        foreach (['data', 'response', 'result', 'body'] as $wrapper) {
            if (isset($json[$wrapper]) && is_array($json[$wrapper])) {
                $inner = $this->extractMessages($json[$wrapper], '');
                $messages = array_merge($messages, $inner);
            }
        }

        // ── 5. Fallback: raw body truncated ───────────────────────────────────
        return $messages ?: [trim($raw)];
    }

    // ── Per-Message Parsing ────────────────────────────────────────────────────

    /**
     * Convert a single raw error string into a structured metadata object.
     *
     * Examples handled:
     *   "owners[0].homeAddress.state must be exactly two character in length"
     *   "phone is invalid"
     *   "zipCode must be 5 digits"
     *   "businessName is required"
     *   "The firstName field is required"
     */
    private function parseMessage(string $raw): ?array
    {
        // Normalise array index notation:  owners[0].state → owners.0.state
        $normalised = preg_replace('/\[(\d+)\]/', '.$1', $raw) ?? $raw;

        $field       = $this->extractFieldPath($normalised);
        $message     = $this->humanise($field, $raw);
        [$fixType, $expected] = $this->classifyError($raw);

        return [
            'field'       => $field,
            'raw_message' => $raw,
            'message'     => $message,
            'fix_type'    => $fixType,
            'expected'    => $expected,
            'path_parts'  => $field !== '' ? explode('.', $field) : [],
        ];
    }

    // ── Field Path Extraction ──────────────────────────────────────────────────

    /**
     * Extract the dot-notation field path from the beginning of an error message.
     *
     *   "owners.0.homeAddress.state must be …"  → "owners.0.homeAddress.state"
     *   "phone is invalid"                       → "phone"
     *   "The firstName field is required"        → "firstName"
     */
    private function extractFieldPath(string $message): string
    {
        // Pattern A: leading path token followed by must/is/should/cannot/requires
        if (preg_match(
            '/^([a-zA-Z_][a-zA-Z0-9_.]*)\s+(?:must|is\s|should|cannot|can\'t|requires|field\s|value\s)/i',
            $message,
            $m
        )) {
            return $m[1];
        }

        // Pattern B: "The <fieldName> field|is|must|value …"
        if (preg_match('/^the\s+([a-zA-Z_][a-zA-Z0-9_.]*)\s+(?:field|is|must|value)/i', $message, $m)) {
            return $m[1];
        }

        // Pattern C: first word looks like a field path (letters, digits, dots, underscores, max 80 chars)
        $firstWord = explode(' ', trim($message))[0];
        if (preg_match('/^[a-zA-Z_][a-zA-Z0-9_.]*$/', $firstWord) && strlen($firstWord) <= 80) {
            return $firstWord;
        }

        return '';
    }

    // ── Error Classification ───────────────────────────────────────────────────

    /**
     * Determine the fix_type and a human-readable expected-value hint.
     *
     * @return array{0: string, 1: string}  [fix_type, expected]
     */
    private function classifyError(string $message): array
    {
        $lower = strtolower($message);

        // ── State abbreviation ────────────────────────────────────────────────
        if (
            str_contains($lower, 'two character') ||
            str_contains($lower, '2 character') ||
            str_contains($lower, 'two-character') ||
            (str_contains($lower, 'state') && str_contains($lower, 'length'))
        ) {
            return ['state_code', '2-letter US state code (e.g. NY, CA, TX)'];
        }

        // ── Phone number ──────────────────────────────────────────────────────
        if (str_contains($lower, 'phone') &&
            (str_contains($lower, 'invalid') || str_contains($lower, 'format') || str_contains($lower, 'must be'))
        ) {
            return ['phone', 'Phone in +1XXXXXXXXXX format (e.g. +12125551234)'];
        }

        // ── ZIP / postal code ─────────────────────────────────────────────────
        if (
            (str_contains($lower, 'zip') || str_contains($lower, 'postal')) &&
            (str_contains($lower, 'digit') || str_contains($lower, 'invalid') || str_contains($lower, 'must be'))
        ) {
            return ['zip', '5-digit ZIP code (e.g. 10001)'];
        }

        // ── Required / empty ──────────────────────────────────────────────────
        if (
            str_contains($lower, 'required') ||
            str_contains($lower, 'must not be empty') ||
            str_contains($lower, 'cannot be empty') ||
            str_contains($lower, 'is missing') ||
            str_contains($lower, 'is blank') ||
            str_contains($lower, 'must be present')
        ) {
            return ['required', 'This field is required — please provide a value'];
        }

        // ── Email ─────────────────────────────────────────────────────────────
        if (str_contains($lower, 'email') &&
            (str_contains($lower, 'invalid') || str_contains($lower, 'format'))
        ) {
            return ['email', 'Valid email address (e.g. name@example.com)'];
        }

        // ── Date ─────────────────────────────────────────────────────────────
        if (str_contains($lower, 'date') &&
            (str_contains($lower, 'invalid') || str_contains($lower, 'format') || str_contains($lower, 'must be'))
        ) {
            return ['date', 'Date in YYYY-MM-DD format (e.g. 1985-06-15)'];
        }

        // ── EIN / SSN / Tax ID ────────────────────────────────────────────────
        if (
            str_contains($lower, 'ssn') ||
            str_contains($lower, 'social security') ||
            str_contains($lower, 'tax id') ||
            str_contains($lower, ' ein') ||
            str_contains($lower, 'fein')
        ) {
            return ['ein', '9-digit EIN or SSN (e.g. 123456789 or 12-3456789)'];
        }

        // ── Numeric ───────────────────────────────────────────────────────────
        if (
            str_contains($lower, 'must be numeric') ||
            str_contains($lower, 'must be a number') ||
            str_contains($lower, 'invalid number') ||
            str_contains($lower, 'not a valid number')
        ) {
            return ['numeric', 'Numeric value only (digits, no letters or symbols)'];
        }

        // ── Length ───────────────────────────────────────────────────────────
        if (str_contains($lower, 'minimum') || str_contains($lower, 'too short') || str_contains($lower, 'at least')) {
            return ['length', 'Value is too short — check minimum length requirement'];
        }
        if (str_contains($lower, 'maximum') || str_contains($lower, 'too long') || str_contains($lower, 'exceeds')) {
            return ['length', 'Value is too long — trim to fit the maximum length'];
        }

        return ['unknown', 'Review lender documentation for the accepted format'];
    }

    // ── Humanise ──────────────────────────────────────────────────────────────

    /**
     * Rewrite the raw message into a cleaner, user-facing sentence.
     */
    private function humanise(string $field, string $raw): string
    {
        $lower = strtolower($raw);
        $label = $this->fieldLabel($field);

        if (str_contains($lower, 'two character') || str_contains($lower, '2 character')) {
            return "{$label} must be a 2-letter US state abbreviation (e.g. NY, CA, TX)";
        }
        if (str_contains($lower, 'phone') && str_contains($lower, 'invalid')) {
            return "{$label} must be in E.164 / international format (e.g. +12125551234)";
        }
        if ((str_contains($lower, 'zip') || str_contains($lower, 'postal')) && str_contains($lower, 'digit')) {
            return "{$label} must be exactly 5 digits";
        }
        if (str_contains($lower, 'required') || str_contains($lower, 'must not be empty')) {
            return "{$label} is required and cannot be left empty";
        }
        if (str_contains($lower, 'email') && str_contains($lower, 'invalid')) {
            return "{$label} must be a valid email address";
        }
        if (str_contains($lower, 'date') && str_contains($lower, 'invalid')) {
            return "{$label} must be a valid date in YYYY-MM-DD format";
        }

        // Remove the leading field path from the original message for cleaner display
        if ($field !== '' && stripos($raw, $field) === 0) {
            return ucfirst($label) . substr($raw, strlen($field));
        }

        return $raw;
    }

    /**
     * Convert a dot-notation field path to a human-readable label.
     *
     *   "owners.0.homeAddress.state" → "Owner 1 Home Address State"
     *   "businessName"               → "Business Name"
     */
    private function fieldLabel(string $path): string
    {
        if ($path === '') {
            return 'This field';
        }

        $parts  = explode('.', $path);
        $labels = [];

        foreach ($parts as $part) {
            if (is_numeric($part)) {
                // Append index (+1 for 1-based display) to the previous label
                if ($labels) {
                    $labels[count($labels) - 1] .= ' ' . ((int) $part + 1);
                }
                continue;
            }
            // camelCase → "Camel Case"
            $spaced = preg_replace('/([A-Z])/', ' $1', $part) ?? $part;
            $labels[] = trim(ucwords(str_replace(['_', '-'], ' ', $spaced)));
        }

        return implode(' ', $labels) ?: 'This field';
    }

    // ── Generic Error Detection ─────────────────────────────────────────────

    /**
     * Returns true when all parsed errors are vague/unactionable (fix_type = unknown).
     * This signals that local payload validation should supplement the errors.
     */
    public function isGenericError(array $parsedErrors): bool
    {
        if (empty($parsedErrors)) {
            return true;
        }
        foreach ($parsedErrors as $err) {
            if (($err['fix_type'] ?? 'unknown') !== 'unknown') {
                return false;
            }
        }
        return true;
    }

    // ── Utilities ─────────────────────────────────────────────────────────────

    private function isAssoc(array $arr): bool
    {
        return array_keys($arr) !== range(0, count($arr) - 1);
    }
}
