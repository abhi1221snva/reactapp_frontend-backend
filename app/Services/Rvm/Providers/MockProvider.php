<?php

namespace App\Services\Rvm\Providers;

use App\Model\Master\Rvm\Drop;
use App\Services\Rvm\DTO\CallbackResult;
use App\Services\Rvm\DTO\DeliveryResult;
use App\Services\Rvm\DTO\HealthStatus;

/**
 * Always-succeeds provider used for tests + the initial shadow rollout.
 *
 * Enabled when config('rvm.providers.mock.enabled') is true, which is the
 * default in local/testing environments.
 */
class MockProvider implements RvmProviderInterface
{
    public function name(): string
    {
        return 'mock';
    }

    public function supports(Drop $drop): bool
    {
        return true;
    }

    public function estimateCost(Drop $drop): int
    {
        return 0;
    }

    public function deliver(Drop $drop): DeliveryResult
    {
        return DeliveryResult::delivered(
            externalId: 'mock_' . $drop->id,
            costCents: 0,
        );
    }

    public function handleCallback(array $payload, array $headers): CallbackResult
    {
        return CallbackResult::ignored();
    }

    public function healthCheck(): HealthStatus
    {
        return HealthStatus::up(0);
    }
}
