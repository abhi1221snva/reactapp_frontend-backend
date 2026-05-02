<?php

namespace App\Http\Controllers;

use App\Model\Master\RegistrationProgress;
use App\Services\SetupStepTracker;
use Illuminate\Http\JsonResponse;

/**
 * SetupProgressController
 *
 * Cache-first progress API. Avoids DB queries on every poll.
 * Only touches the database when:
 *   1. Cache has no data (fallback)
 *   2. Setup is completed (to get auto-login JWT)
 */
class SetupProgressController extends Controller
{
    /**
     * GET /signup/setup-steps/{id}
     */
    public function show(int $id): JsonResponse
    {
        // ── 1. Try cache first (zero DB queries) ─────────────────────────
        $stepData = SetupStepTracker::getProgress($id);

        if ($stepData) {
            // Still in progress — return cache data only, no DB hit
            if (!$stepData['completed'] && !$stepData['failed']) {
                return response()->json([
                    'status' => true,
                    'data'   => $stepData,
                ]);
            }

            // Completed or failed — need DB for auto-login / error details
            $progress = RegistrationProgress::find($id);
            if ($progress) {
                $stepData = $this->enrichWithRegistrationData($stepData, $progress);
            }

            return response()->json([
                'status' => true,
                'data'   => $stepData,
            ]);
        }

        // ── 2. Fallback: synthesize from DB (cache miss) ─────────────────
        $progress = RegistrationProgress::find($id);

        if (!$progress) {
            return response()->json([
                'status'  => false,
                'message' => 'Progress record not found.',
            ], 404);
        }

        return response()->json([
            'status' => true,
            'data'   => $this->synthesizeFromStage($progress),
        ]);
    }

    /**
     * Enrich cache data with auto-login info from DB.
     */
    private function enrichWithRegistrationData(array $stepData, RegistrationProgress $progress): array
    {
        $stepData['stage'] = $progress->stage;
        $stepData['ready'] = $progress->stage === RegistrationProgress::STAGE_COMPLETED;

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
            $stepData['error_message'] = 'Account setup encountered an issue. Please contact support.';
        }

        return $stepData;
    }

    /**
     * Synthesize 7-step progress from DB when cache is empty.
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

        $stepThresholds = [
            ['name' => 'Profile Setup',         'completes_at' => 1],
            ['name' => 'Campaign Menu Setup',   'completes_at' => 2],
            ['name' => 'Lead Menu Setup',       'completes_at' => 3],
            ['name' => 'DID Setup',             'completes_at' => 3],
            ['name' => 'Email Template Setup',  'completes_at' => 4],
            ['name' => 'SMS Template Setup',    'completes_at' => 5],
            ['name' => 'Final Initialization',  'completes_at' => 6],
        ];

        $isFailed    = $progress->stage === RegistrationProgress::STAGE_FAILED;
        $isCompleted = $progress->stage === RegistrationProgress::STAGE_COMPLETED;
        $currentStep = null;
        $steps       = [];

        foreach ($stepThresholds as $def) {
            if ($isCompleted) {
                $status = 'completed';
            } elseif ($isFailed) {
                $status = $currentIdx >= $def['completes_at']
                    ? 'completed'
                    : ($currentIdx === $def['completes_at'] - 1 ? 'failed' : 'pending');
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

            $steps[] = ['name' => $def['name'], 'status' => $status];
        }

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

        // Auto-login on completion
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
            $result['error_message'] = 'Account setup encountered an issue. Please contact support.';
        }

        return $result;
    }
}
