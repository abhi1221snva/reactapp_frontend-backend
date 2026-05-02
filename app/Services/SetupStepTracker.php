<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * SetupStepTracker
 *
 * Lightweight, cache-based tracker for fine-grained client setup progress.
 * Used alongside the existing RegistrationProgress model to give the frontend
 * a premium step-by-step provisioning experience.
 *
 * Cache key: "setup_steps:{progressId}"
 * TTL: 30 minutes (setup never takes that long; auto-cleans)
 *
 * Each step stores: { status, started_at, completed_at }
 * Global metadata: { started_at, completed_at, elapsed }
 */
class SetupStepTracker
{
    // The 7 setup steps (display order)
    const STEPS = [
        'profile_setup',
        'campaign_menu_setup',
        'lead_menu_setup',
        'did_setup',
        'email_template_setup',
        'sms_template_setup',
        'final_initialization',
    ];

    // Human-readable labels
    const LABELS = [
        'profile_setup'         => 'Profile Setup',
        'campaign_menu_setup'   => 'Campaign Menu Setup',
        'lead_menu_setup'       => 'Lead Menu Setup',
        'did_setup'             => 'DID Setup',
        'email_template_setup'  => 'Email Template Setup',
        'sms_template_setup'    => 'SMS Template Setup',
        'final_initialization'  => 'Final Initialization',
    ];

    const CACHE_TTL = 1800; // 30 minutes

    /**
     * Initialize tracking for a provisioning run.
     */
    public static function init(int $progressId): void
    {
        $data = [
            'started_at'   => microtime(true),
            'completed_at' => null,
            'steps'        => [],
        ];

        foreach (self::STEPS as $step) {
            $data['steps'][$step] = [
                'status'       => 'pending',
                'started_at'   => null,
                'completed_at' => null,
            ];
        }

        self::save($progressId, $data);
    }

    /**
     * Mark a step as running.
     */
    public static function start(int $progressId, string $step): void
    {
        $data = self::load($progressId);
        if (!$data || !isset($data['steps'][$step])) return;

        $data['steps'][$step]['status']     = 'running';
        $data['steps'][$step]['started_at'] = microtime(true);

        self::save($progressId, $data);
    }

    /**
     * Mark a step as completed.
     */
    public static function complete(int $progressId, string $step): void
    {
        $data = self::load($progressId);
        if (!$data || !isset($data['steps'][$step])) return;

        $data['steps'][$step]['status']       = 'completed';
        $data['steps'][$step]['completed_at'] = microtime(true);

        self::save($progressId, $data);
    }

    /**
     * Mark all steps as completed (setup finished).
     */
    public static function finalize(int $progressId): void
    {
        $data = self::load($progressId);
        if (!$data) return;

        $now = microtime(true);

        foreach ($data['steps'] as $step => &$info) {
            if ($info['status'] !== 'completed') {
                $info['status']       = 'completed';
                $info['completed_at'] = $info['completed_at'] ?? $now;
                $info['started_at']   = $info['started_at'] ?? $now;
            }
        }

        $data['completed_at'] = $now;
        self::save($progressId, $data);
    }

    /**
     * Mark as failed — freeze current state.
     */
    public static function fail(int $progressId): void
    {
        $data = self::load($progressId);
        if (!$data) return;

        // Mark any running step as failed
        foreach ($data['steps'] as $step => &$info) {
            if ($info['status'] === 'running') {
                $info['status'] = 'failed';
            }
        }

        $data['completed_at'] = microtime(true);
        self::save($progressId, $data);
    }

    /**
     * Get the full progress state for API response.
     */
    public static function getProgress(int $progressId): ?array
    {
        $data = self::load($progressId);
        if (!$data) return null;

        $now     = microtime(true);
        $elapsed = round($now - $data['started_at'], 1);

        // Determine current step
        $currentStep = null;
        foreach (self::STEPS as $step) {
            if ($data['steps'][$step]['status'] === 'running') {
                $currentStep = self::LABELS[$step];
                break;
            }
        }

        // Build steps array for API
        $steps = [];
        foreach (self::STEPS as $step) {
            $info = $data['steps'][$step];
            $stepData = [
                'name'   => self::LABELS[$step],
                'status' => $info['status'],
            ];

            if ($info['started_at']) {
                $stepData['started_at'] = round($info['started_at'] - $data['started_at'], 2);
            }
            if ($info['completed_at']) {
                $stepData['completed_at'] = round($info['completed_at'] - $data['started_at'], 2);
                $stepData['duration'] = round($info['completed_at'] - ($info['started_at'] ?? $info['completed_at']), 2);
            }

            $steps[] = $stepData;
        }

        $isCompleted = $data['completed_at'] !== null &&
            collect($data['steps'])->every(fn($s) => $s['status'] === 'completed');

        return [
            'elapsed_time' => $elapsed,
            'current_step' => $currentStep,
            'completed'    => $isCompleted,
            'failed'       => collect($data['steps'])->contains(fn($s) => $s['status'] === 'failed'),
            'steps'        => $steps,
        ];
    }

    // ── Internal helpers ──────────────────────────────────────────────────

    private static function cacheKey(int $progressId): string
    {
        return "setup_steps:{$progressId}";
    }

    private static function save(int $progressId, array $data): void
    {
        try {
            Cache::put(self::cacheKey($progressId), $data, self::CACHE_TTL);
        } catch (\Throwable $e) {
            // Non-fatal — tracking failure must never block provisioning
            Log::warning('SetupStepTracker: cache write failed', [
                'progress_id' => $progressId,
                'error'       => $e->getMessage(),
            ]);
        }
    }

    private static function load(int $progressId): ?array
    {
        try {
            return Cache::get(self::cacheKey($progressId));
        } catch (\Throwable $e) {
            return null;
        }
    }
}
