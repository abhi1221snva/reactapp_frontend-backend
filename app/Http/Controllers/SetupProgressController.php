<?php

namespace App\Http\Controllers;

use App\Model\Master\RegistrationProgress;
use App\Services\SetupStepTracker;
use Illuminate\Http\JsonResponse;

/**
 * SetupProgressController
 *
 * Provides a fine-grained, step-by-step progress API for the premium
 * setup experience. Reads from the cache-based SetupStepTracker alongside
 * the existing RegistrationProgress model.
 *
 * This controller is read-only and does not modify any provisioning logic.
 */
class SetupProgressController extends Controller
{
    /**
     * GET /signup/setup-steps/{id}
     *
     * Returns detailed step-by-step progress for a provisioning run.
     * Falls back to synthesizing steps from RegistrationProgress if
     * cache tracking data is unavailable.
     */
    public function show(int $id): JsonResponse
    {
        $progress = RegistrationProgress::find($id);

        if (!$progress) {
            return response()->json([
                'status'  => false,
                'message' => 'Progress record not found.',
            ], 404);
        }

        // Try cache-based fine-grained tracking first
        $stepData = SetupStepTracker::getProgress($id);

        if ($stepData) {
            return response()->json([
                'status' => true,
                'data'   => $this->enrichWithRegistrationData($stepData, $progress),
            ]);
        }

        // Fallback: synthesize from the coarse RegistrationProgress stages
        return response()->json([
            'status' => true,
            'data'   => $this->synthesizeFromStage($progress),
        ]);
    }

    /**
     * Enrich cache-based step data with auto-login info from RegistrationProgress.
     */
    private function enrichWithRegistrationData(array $stepData, RegistrationProgress $progress): array
    {
        $stepData['stage']    = $progress->stage;
        $stepData['ready']    = $progress->stage === RegistrationProgress::STAGE_COMPLETED;
        $stepData['failed']   = $progress->stage === RegistrationProgress::STAGE_FAILED;

        // Include auto-login data when completed
        if ($stepData['ready'] && $progress->user_id) {
            try {
                $user = \App\Model\User::find($progress->user_id);
                if ($user) {
                    $auth        = new \App\Model\Authentication();
                    $tokenResult = $auth->loginByUserId($progress->user_id);
                    $stepData['token'] = $tokenResult['token'] ?? null;
                    $stepData['user']  = [
                        'id'            => $user->id,
                        'first_name'    => $user->first_name,
                        'last_name'     => $user->last_name,
                        'email'         => $user->email,
                        'level'         => $user->user_level ?? 6,
                        'extension'     => $user->extension,
                        'alt_extension' => $user->alt_extension,
                        'parent_id'     => $user->parent_id,
                    ];
                }
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('SetupProgressController: auto-login failed', [
                    'user_id' => $progress->user_id,
                    'error'   => $e->getMessage(),
                ]);
            }
        }

        if ($stepData['failed']) {
            $stepData['error_message'] = 'Account setup encountered an issue. Please contact support or try again.';
        }

        return $stepData;
    }

    /**
     * Synthesize 7-step progress from the coarse RegistrationProgress stages.
     * Used as fallback when cache data is unavailable (e.g., cache cleared).
     */
    private function synthesizeFromStage(RegistrationProgress $progress): array
    {
        $stageOrder = [
            RegistrationProgress::STAGE_QUEUED,
            RegistrationProgress::STAGE_CREATING_RECORD,
            RegistrationProgress::STAGE_CREATING_DATABASE,
            RegistrationProgress::STAGE_SEEDING_DATA,
            RegistrationProgress::STAGE_ASSIGNING_TRIAL,
            RegistrationProgress::STAGE_SENDING_WELCOME,
            RegistrationProgress::STAGE_COMPLETED,
        ];

        $currentIdx = array_search($progress->stage, $stageOrder);
        if ($currentIdx === false) $currentIdx = -1;

        // Map backend stages to the 7 UI steps (which stage must be reached for completion)
        $stepThresholds = [
            ['name' => 'Profile Setup',         'completes_at' => 1], // after creating_record
            ['name' => 'Campaign Menu Setup',   'completes_at' => 2], // after creating_database
            ['name' => 'Lead Menu Setup',       'completes_at' => 3], // during seeding_data
            ['name' => 'DID Setup',             'completes_at' => 3], // during seeding_data
            ['name' => 'Email Template Setup',  'completes_at' => 4], // after assigning_trial
            ['name' => 'SMS Template Setup',    'completes_at' => 5], // after sending_welcome
            ['name' => 'Final Initialization',  'completes_at' => 6], // completed
        ];

        $isFailed = $progress->stage === RegistrationProgress::STAGE_FAILED;
        $isCompleted = $progress->stage === RegistrationProgress::STAGE_COMPLETED;
        $currentStep = null;
        $steps = [];

        foreach ($stepThresholds as $i => $def) {
            if ($isCompleted) {
                $status = 'completed';
            } elseif ($isFailed) {
                $status = $currentIdx >= $def['completes_at'] ? 'completed' : ($currentIdx === $def['completes_at'] - 1 ? 'failed' : 'pending');
            } elseif ($currentIdx >= $def['completes_at']) {
                $status = 'completed';
            } elseif ($currentIdx === $def['completes_at'] - 1) {
                $status = 'running';
            } else {
                $status = 'pending';
            }

            if ($status === 'running') {
                $currentStep = $def['name'];
            }

            $steps[] = [
                'name'   => $def['name'],
                'status' => $status,
            ];
        }

        // Calculate elapsed time from created_at
        $elapsed = $progress->created_at
            ? round(now()->floatDiffInSeconds($progress->created_at), 1)
            : 0;

        $result = [
            'elapsed_time' => $elapsed,
            'current_step' => $currentStep,
            'completed'    => $isCompleted,
            'failed'       => $isFailed,
            'steps'        => $steps,
            'stage'        => $progress->stage,
            'ready'        => $isCompleted,
        ];

        // Include auto-login data when completed
        if ($isCompleted && $progress->user_id) {
            try {
                $user = \App\Model\User::find($progress->user_id);
                if ($user) {
                    $auth        = new \App\Model\Authentication();
                    $tokenResult = $auth->loginByUserId($progress->user_id);
                    $result['token'] = $tokenResult['token'] ?? null;
                    $result['user']  = [
                        'id'            => $user->id,
                        'first_name'    => $user->first_name,
                        'last_name'     => $user->last_name,
                        'email'         => $user->email,
                        'level'         => $user->user_level ?? 6,
                        'extension'     => $user->extension,
                        'alt_extension' => $user->alt_extension,
                        'parent_id'     => $user->parent_id,
                    ];
                }
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('SetupProgressController: auto-login failed', [
                    'user_id' => $progress->user_id,
                    'error'   => $e->getMessage(),
                ]);
            }
        }

        if ($isFailed) {
            $result['error_message'] = 'Account setup encountered an issue. Please contact support or try again.';
        }

        return $result;
    }
}
