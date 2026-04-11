<?php

namespace App\Services\Rvm\DTO;

final class Priority
{
    public const INSTANT = 'instant';
    public const NORMAL  = 'normal';
    public const BULK    = 'bulk';

    public const ALL = [self::INSTANT, self::NORMAL, self::BULK];

    /**
     * Redis queue name for a given priority.
     */
    public static function queue(string $priority): string
    {
        return match ($priority) {
            self::INSTANT => 'rvm.instant',
            self::BULK    => 'rvm.bulk',
            default       => 'rvm.normal',
        };
    }

    public static function isValid(string $priority): bool
    {
        return in_array($priority, self::ALL, true);
    }
}
