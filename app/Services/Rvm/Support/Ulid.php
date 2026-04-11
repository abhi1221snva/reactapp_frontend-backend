<?php

namespace App\Services\Rvm\Support;

/**
 * Minimal ULID generator — 26-char Crockford base32, time-sortable.
 *
 * We use ULIDs (not UUIDs) for drop/campaign IDs so the primary key is
 * roughly monotonically increasing, which plays well with InnoDB and
 * monthly partitioning.
 */
final class Ulid
{
    private const ENCODING = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    public static function generate(): string
    {
        $time = (int) (microtime(true) * 1000);
        $timeChars = '';
        for ($i = 9; $i >= 0; $i--) {
            $mod = $time % 32;
            $timeChars = self::ENCODING[$mod] . $timeChars;
            $time = ($time - $mod) / 32;
        }

        $randChars = '';
        $bytes = random_bytes(16);
        for ($i = 0; $i < 16; $i++) {
            $randChars .= self::ENCODING[ord($bytes[$i]) & 0x1F];
        }

        return $timeChars . $randChars;
    }
}
