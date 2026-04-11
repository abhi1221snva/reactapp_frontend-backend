<?php

namespace App\Services\Rvm\Exceptions;

class InsufficientCreditsException extends RvmException
{
    public function __construct(
        public readonly int $balanceCents,
        public readonly int $requiredCents,
    ) {
        parent::__construct(sprintf(
            'Wallet balance %d cents below required %d cents',
            $balanceCents,
            $requiredCents
        ));
    }

    public function errorCode(): string { return 'rvm.insufficient_credits'; }
    public function httpStatus(): int { return 402; }
    public function details(): array
    {
        return [
            'balance_cents' => $this->balanceCents,
            'required_cents' => $this->requiredCents,
        ];
    }
}
