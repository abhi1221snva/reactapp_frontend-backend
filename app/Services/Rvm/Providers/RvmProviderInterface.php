<?php

namespace App\Services\Rvm\Providers;

use App\Model\Master\Rvm\Drop;
use App\Services\Rvm\DTO\CallbackResult;
use App\Services\Rvm\DTO\DeliveryResult;
use App\Services\Rvm\DTO\HealthStatus;

/**
 * Pluggable RVM provider driver.
 *
 * One implementation per vendor (Asterisk, Twilio, Plivo, Slybroadcast).
 * Drivers MUST be side-effect-free for rejected drops — compliance gates
 * (DNC, quiet hours, wallet) run BEFORE deliver() is called.
 *
 * Drivers MUST NOT swallow transient errors. Throw ProviderTransientError
 * so the RvmProviderRouter can failover / the queue can retry.
 */
interface RvmProviderInterface
{
    /**
     * Short machine name, e.g. "asterisk", "twilio", "plivo", "slybroadcast".
     */
    public function name(): string;

    /**
     * Can this provider deliver this specific drop?
     * Used by the router to filter candidates (e.g. a provider that doesn't
     * support international numbers can return false for non-US E.164).
     */
    public function supports(Drop $drop): bool;

    /**
     * Best-effort cost estimate in cents. Called before the wallet reserve.
     */
    public function estimateCost(Drop $drop): int;

    /**
     * Hand the drop off to the provider.
     *
     * @throws \App\Services\Rvm\Exceptions\ProviderTransientError
     * @throws \App\Services\Rvm\Exceptions\ProviderPermanentError
     */
    public function deliver(Drop $drop): DeliveryResult;

    /**
     * Process an inbound callback/webhook from the provider.
     * MUST verify the signature before touching any state.
     */
    public function handleCallback(array $payload, array $headers): CallbackResult;

    /**
     * Is the provider currently reachable and accepting traffic?
     * Called by the circuit breaker + /healthz.
     */
    public function healthCheck(): HealthStatus;
}
