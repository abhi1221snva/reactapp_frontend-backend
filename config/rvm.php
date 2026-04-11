<?php

/**
 * RVM v2 — unified pipeline configuration.
 *
 * This config is NEW and does not touch legacy RVM behaviour. Until
 * RVM_USE_NEW_PIPELINE=true for a tenant, the old /ringless-voicemail-drop*
 * routes and SendRvmJob remain authoritative.
 */
return [

    // ── Master kill switch ─────────────────────────────────────────────────
    // When false, v1 legacy pipeline is the only path. When true, NEW code
    // paths become eligible (still gated per-tenant via clients.rvm_pipeline).
    'use_new_pipeline' => env('RVM_USE_NEW_PIPELINE', false),

    // ── Cost defaults (cents) ──────────────────────────────────────────────
    // Tenant-level overrides live in rvm_providers (TBD phase 1).
    'default_cost_cents' => env('RVM_DEFAULT_COST_CENTS', 2),
    'instant_cost_cents' => env('RVM_INSTANT_COST_CENTS', 3),

    // ── Default rate limits ────────────────────────────────────────────────
    // Per-API-key limits come from rvm_api_keys.rate_limit_per_minute.
    'rate_limits' => [
        'tenant_per_minute' => env('RVM_TENANT_PER_MINUTE', 5000),
        'api_key_per_minute' => env('RVM_APIKEY_PER_MINUTE', 2000),
        'phone_window_hours' => env('RVM_PHONE_WINDOW_HOURS', 24),
        'provider_per_second' => env('RVM_PROVIDER_PER_SECOND', 30),
    ],

    // ── Retry / lifetime ───────────────────────────────────────────────────
    'retry' => [
        'max_tries' => 5,
        'backoff_seconds' => [15, 60, 300, 900, 3600],
        // Any drop older than this is expired + moved to DLQ regardless of state.
        'max_lifetime_hours' => 24,
    ],

    // ── Provider drivers ───────────────────────────────────────────────────
    // Each driver is enabled/disabled independently. The router reads this
    // list at construction time.
    'providers' => [
        'mock' => [
            'enabled' => env('RVM_PROVIDER_MOCK', env('APP_ENV') !== 'production'),
        ],
        'asterisk' => [
            'enabled' => env('RVM_PROVIDER_ASTERISK', true),
            // Reuses existing AsteriskServer table; no extra config needed.
        ],
        'twilio' => [
            'enabled' => env('RVM_PROVIDER_TWILIO', false),
            // Reuses existing twilio_accounts table for per-tenant creds.
        ],
        'plivo' => [
            'enabled' => env('RVM_PROVIDER_PLIVO', false),
            // Reuses existing plivo_accounts table for per-tenant creds.
        ],
        'slybroadcast' => [
            'enabled' => env('RVM_PROVIDER_SLYBROADCAST', false),
            'endpoint' => env('RVM_SLYBROADCAST_URL', 'https://www.slybroadcast.com/gateway/vmb.php'),
        ],
    ],

    // ── Circuit breaker ────────────────────────────────────────────────────
    'circuit_breaker' => [
        'failure_threshold_pct' => 50,
        'sample_window_seconds' => 60,
        'cooldown_seconds' => 120,
    ],

    // ── Compliance defaults (when no campaign override) ────────────────────
    'compliance' => [
        'quiet_start' => env('RVM_QUIET_START', '09:00:00'),
        'quiet_end' => env('RVM_QUIET_END', '20:00:00'),
        'respect_global_dnc' => true,
    ],

    // ── Webhook delivery ───────────────────────────────────────────────────
    'webhooks' => [
        'timeout_seconds' => 5,
        'max_attempts' => 6,
        'backoff_seconds' => [30, 120, 600, 3600, 21600, 86400],
        'auto_disable_after_consecutive_failures' => 10,
    ],

    // ── Operator alerting ──────────────────────────────────────────────────
    // Slack incoming-webhook URL. When set, every tenant mode change
    // involving `live` (to or from) and every fleet rollback-all
    // operation posts a message here. Leave empty to disable.
    'slack_webhook_url' => env('SLACK_RVM_WEBHOOK_URL', ''),
    'slack_timeout_seconds' => (int) env('SLACK_RVM_TIMEOUT', 3),
];
