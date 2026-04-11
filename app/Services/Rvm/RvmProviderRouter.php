<?php

namespace App\Services\Rvm;

use App\Model\Master\Rvm\Drop;
use App\Services\Rvm\Exceptions\ProviderUnavailableException;
use App\Services\Rvm\Providers\RvmProviderInterface;
use Illuminate\Support\Facades\Redis;

/**
 * Provider selection + failover + circuit breaker.
 *
 * Register drivers in the service container (AppServiceProvider):
 *
 *   $this->app->tag([AsteriskProvider::class, TwilioProvider::class, ...], 'rvm.providers');
 *
 * Then resolve an instance with:
 *
 *   $router = app(RvmProviderRouter::class);
 */
class RvmProviderRouter
{
    /** @var RvmProviderInterface[] */
    private array $providers;

    public function __construct(iterable $providers)
    {
        $this->providers = [];
        foreach ($providers as $p) {
            $this->providers[$p->name()] = $p;
        }
    }

    /**
     * Pick the best provider for a drop, honouring:
     *   - caller hint (drop->provider set explicitly)
     *   - supports() filter
     *   - circuit-breaker health
     *   - per-tenant weight overrides
     */
    public function pickProvider(Drop $drop): RvmProviderInterface
    {
        $candidates = $this->filterCandidates($drop);

        if (empty($candidates)) {
            throw new ProviderUnavailableException(
                "No healthy provider for drop {$drop->id}"
            );
        }

        // TODO: tenant-weighted sort via rvm_providers override table
        return $candidates[0];
    }

    /**
     * Pick a failover provider, excluding one already attempted.
     */
    public function pickFailover(Drop $drop, string $excludeName): ?RvmProviderInterface
    {
        $candidates = array_values(array_filter(
            $this->filterCandidates($drop),
            fn($p) => $p->name() !== $excludeName
        ));
        return $candidates[0] ?? null;
    }

    public function markUnhealthy(string $providerName, int $ttlSeconds = 120): void
    {
        Redis::setex("rvm:breaker:{$providerName}", $ttlSeconds, '1');
    }

    public function markHealthy(string $providerName): void
    {
        Redis::del("rvm:breaker:{$providerName}");
    }

    public function isHealthy(string $providerName): bool
    {
        return !Redis::exists("rvm:breaker:{$providerName}");
    }

    /** @return RvmProviderInterface[] */
    private function filterCandidates(Drop $drop): array
    {
        $candidates = array_values($this->providers);

        // Honour caller hint
        if ($drop->provider && isset($this->providers[$drop->provider])) {
            $candidates = [$this->providers[$drop->provider]];
        }

        return array_values(array_filter(
            $candidates,
            fn(RvmProviderInterface $p) => $p->supports($drop) && $this->isHealthy($p->name())
        ));
    }
}
