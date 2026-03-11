<?php

namespace App\Http\Controllers;

use App\Model\Master\OnboardingProgress;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Onboarding wizard API.
 *
 * Routes (JWT required):
 *   GET  /onboarding          → getProgress()
 *   POST /onboarding/complete  → completeStep()
 *   POST /onboarding/reset     → reset()  (admin only)
 */
class OnboardingController extends Controller
{
    /**
     * GET /onboarding
     * Returns the current onboarding progress for the authenticated user.
     */
    public function getProgress(Request $request)
    {
        try {
            $userId   = $request->auth->id;
            $clientId = $request->auth->parent_id;

            $progress = OnboardingProgress::findOrInit($userId, $clientId);

            return $this->successResponse('Onboarding progress', [
                'progress_pct'  => $progress->progress_pct,
                'is_complete'   => $progress->progress_pct >= 100,
                'completed_at'  => $progress->completed_at,
                'steps'         => $progress->toStepArray(),
            ]);
        } catch (\Throwable $e) {
            return $this->failResponse('Failed to load onboarding progress', [], $e);
        }
    }

    /**
     * POST /onboarding/complete
     * Mark a step as completed.
     *
     * Body: { "step": "email_verified" }
     *
     * Valid steps: email_verified, phone_verified, first_agent_created,
     *              lead_fields_set, dialer_configured
     */
    public function completeStep(Request $request)
    {
        $this->validate($request, [
            'step' => 'required|string|in:' . implode(',', OnboardingProgress::STEPS),
        ]);

        try {
            $userId   = $request->auth->id;
            $clientId = $request->auth->parent_id;
            $step     = $request->input('step');

            $progress = OnboardingProgress::findOrInit($userId, $clientId);

            if ($progress->{$step}) {
                return $this->successResponse('Step already completed', [
                    'progress_pct' => $progress->progress_pct,
                    'steps'        => $progress->toStepArray(),
                ]);
            }

            $progress->completeStep($step);

            Log::info('Onboarding step completed', [
                'user_id'   => $userId,
                'client_id' => $clientId,
                'step'      => $step,
                'pct'       => $progress->progress_pct,
            ]);

            return $this->successResponse('Step completed', [
                'progress_pct' => $progress->progress_pct,
                'is_complete'  => $progress->progress_pct >= 100,
                'steps'        => $progress->toStepArray(),
            ]);
        } catch (\Throwable $e) {
            return $this->failResponse('Failed to complete onboarding step', [], $e);
        }
    }

    /**
     * POST /onboarding/reset  (Admin only)
     * Resets onboarding progress for a user — useful for testing.
     *
     * Body: { "user_id": 5 }  (optional — defaults to own user)
     */
    public function reset(Request $request)
    {
        $requestorLevel = $request->auth->level ?? 0;
        if ($requestorLevel < 7) {
            return $this->failResponse('Unauthorized', [], null, 403);
        }

        $userId   = $request->input('user_id', $request->auth->id);
        $clientId = $request->auth->parent_id;

        OnboardingProgress::where('user_id', $userId)
            ->where('client_id', $clientId)
            ->delete();

        return $this->successResponse('Onboarding progress reset', []);
    }
}
