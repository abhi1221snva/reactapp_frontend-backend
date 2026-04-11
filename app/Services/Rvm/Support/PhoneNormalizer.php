<?php

namespace App\Services\Rvm\Support;

use App\Services\Rvm\Exceptions\InvalidPhoneException;

/**
 * Phone number normalizer.
 *
 * Minimal E.164 shaping — this intentionally does NOT try to replace a full
 * libphonenumber. For NANP (the primary RVM market) it handles the common
 * dirty inputs: with/without +1, parentheses, dashes, spaces, leading 0/1.
 *
 * If you need true international validation, swap in giggsey/libphonenumber-for-php
 * and delegate here — the interface stays the same.
 */
final class PhoneNormalizer
{
    public static function toE164(string $raw, string $defaultCountry = 'US'): string
    {
        $digits = preg_replace('/[^0-9+]/', '', trim($raw));
        if ($digits === '' || $digits === null) {
            throw new InvalidPhoneException("Phone number is empty");
        }

        // If caller supplied a leading + we trust it (as long as the rest looks sane).
        if (str_starts_with($digits, '+')) {
            $rest = substr($digits, 1);
            if (!ctype_digit($rest) || strlen($rest) < 8 || strlen($rest) > 15) {
                throw new InvalidPhoneException("Invalid E.164 number: {$raw}");
            }
            return '+' . $rest;
        }

        // NANP fallback
        if ($defaultCountry === 'US') {
            $digits = ltrim($digits, '0');
            if (strlen($digits) === 11 && str_starts_with($digits, '1')) {
                return '+' . $digits;
            }
            if (strlen($digits) === 10) {
                return '+1' . $digits;
            }
        }

        throw new InvalidPhoneException("Cannot normalize phone: {$raw}");
    }

    /**
     * Extract the NANP area code (first 3 digits after country code).
     * Returns null for non-NANP numbers.
     */
    public static function nanpAreaCode(string $e164): ?string
    {
        if (!str_starts_with($e164, '+1')) return null;
        return substr($e164, 2, 3);
    }
}
