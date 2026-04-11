<?php

namespace App\Services\Rvm\DTO;

/**
 * Result of an attempted legacy→v2 divert.
 *
 * `$diverted === true` means the legacy payload has been translated into
 * an rvm_drops row and the legacy dispatcher SHOULD short-circuit.
 * `$diverted === false` means the caller must continue with the legacy
 * path — the reason is in $reason (e.g. 'mode_legacy', 'already_diverted',
 * 'translate_failed', 'dropservice_rejected:...').
 *
 * This DTO is immutable and never carries exceptions — RvmDivertService
 * is contractually not allowed to throw, so failures surface here.
 */
final class DivertResult
{
    public function __construct(
        public readonly bool $diverted,
        public readonly ?string $v2DropId,
        public readonly string $mode,
        public readonly string $reason,
    ) {}

    public static function diverted(string $dropId, string $mode): self
    {
        return new self(true, $dropId, $mode, 'ok');
    }

    public static function skipped(string $reason, string $mode = 'legacy'): self
    {
        return new self(false, null, $mode, $reason);
    }
}
