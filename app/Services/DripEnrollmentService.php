<?php

namespace App\Services;

use App\Model\Client\DripV2Campaign;
use App\Model\Client\DripV2Enrollment;
use App\Model\Client\DripV2Step;
use App\Model\Client\DripV2Unsubscribe;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DripEnrollmentService
{
    /**
     * Enroll a single lead into a campaign.
     */
    public static function enroll(
        string $clientId,
        int    $leadId,
        int    $campaignId,
        ?int   $enrolledBy = null,
        string $via = 'manual',
        ?string $triggerRule = null
    ): ?DripV2Enrollment {
        $conn = "mysql_{$clientId}";

        try {
            $campaign = DripV2Campaign::on($conn)->findOrFail($campaignId);

            if ($campaign->status !== 'active') {
                throw new \Exception('Campaign is not active.');
            }

            // Check unsubscribe
            $unsub = DripV2Unsubscribe::on($conn)
                ->where('lead_id', $leadId)
                ->whereIn('channel', [$campaign->channel, 'both'])
                ->first();
            if ($unsub) {
                Log::info("DripEnroll: Lead {$leadId} is unsubscribed — skipping", ['client' => $clientId]);
                return null;
            }

            // Check duplicate enrollment
            $existing = DripV2Enrollment::on($conn)
                ->where('campaign_id', $campaignId)
                ->where('lead_id', $leadId)
                ->where('status', 'active')
                ->first();
            if ($existing) {
                return $existing; // already enrolled
            }

            // Get first active step
            $firstStep = DripV2Step::on($conn)
                ->where('campaign_id', $campaignId)
                ->where('is_active', true)
                ->orderBy('position')
                ->first();

            if (!$firstStep) {
                throw new \Exception('Campaign has no active steps.');
            }

            // Calculate next send time
            $nextSendAt = Carbon::now()->addSeconds($firstStep->delayInSeconds());

            $enrollment = new DripV2Enrollment();
            $enrollment->setConnection($conn);
            $enrollment->fill([
                'campaign_id'     => $campaignId,
                'lead_id'         => $leadId,
                'current_step_id' => $firstStep->id,
                'status'          => 'active',
                'enrolled_by'     => $enrolledBy,
                'enrolled_via'    => $via,
                'trigger_rule'    => $triggerRule,
                'next_send_at'    => $nextSendAt,
            ]);
            $enrollment->save();

            // Log activity
            ActivityService::log(
                $clientId,
                $leadId,
                'drip_enrolled',
                "Enrolled in campaign: {$campaign->name}",
                null,
                ['campaign_id' => $campaignId, 'via' => $via],
                $enrolledBy ?? 0
            );

            return $enrollment;
        } catch (\Throwable $e) {
            Log::error('DripEnrollmentService::enroll failed', [
                'client_id'   => $clientId,
                'lead_id'     => $leadId,
                'campaign_id' => $campaignId,
                'error'       => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Bulk-enroll multiple leads into a campaign.
     */
    public static function enrollBulk(string $clientId, array $leadIds, int $campaignId, ?int $enrolledBy = null): array
    {
        $results = ['enrolled' => 0, 'skipped' => 0, 'errors' => 0];

        foreach ($leadIds as $leadId) {
            try {
                $enrollment = self::enroll($clientId, (int) $leadId, $campaignId, $enrolledBy, 'manual');
                if ($enrollment) {
                    $results['enrolled']++;
                } else {
                    $results['skipped']++;
                }
            } catch (\Throwable $e) {
                $results['errors']++;
            }
        }

        return $results;
    }

    /**
     * Unenroll from a campaign.
     */
    public static function unenroll(string $clientId, int $enrollmentId, string $reason = 'manual'): DripV2Enrollment
    {
        $conn = "mysql_{$clientId}";
        $enrollment = DripV2Enrollment::on($conn)->findOrFail($enrollmentId);

        $enrollment->status         = 'stopped';
        $enrollment->stopped_reason = $reason;
        $enrollment->stopped_at     = Carbon::now();
        $enrollment->next_send_at   = null;
        $enrollment->save();

        // Log activity
        $campaign = DripV2Campaign::on($conn)->find($enrollment->campaign_id);
        ActivityService::log(
            $clientId,
            $enrollment->lead_id,
            'drip_unenrolled',
            "Removed from campaign: " . ($campaign->name ?? "#{$enrollment->campaign_id}"),
            "Reason: {$reason}",
            ['enrollment_id' => $enrollmentId, 'reason' => $reason],
            0
        );

        return $enrollment;
    }

    /**
     * Advance enrollment to the next step after a successful send.
     */
    public static function advanceToNextStep(string $clientId, DripV2Enrollment $enrollment): void
    {
        $conn = "mysql_{$clientId}";

        $currentStep = DripV2Step::on($conn)->find($enrollment->current_step_id);
        if (!$currentStep) {
            self::completeEnrollment($clientId, $enrollment);
            return;
        }

        // Find next active step
        $nextStep = DripV2Step::on($conn)
            ->where('campaign_id', $enrollment->campaign_id)
            ->where('position', '>', $currentStep->position)
            ->where('is_active', true)
            ->orderBy('position')
            ->first();

        if (!$nextStep) {
            self::completeEnrollment($clientId, $enrollment);
            return;
        }

        $enrollment->current_step_id = $nextStep->id;
        $enrollment->next_send_at    = Carbon::now()->addSeconds($nextStep->delayInSeconds());
        $enrollment->save();
    }

    /**
     * Mark enrollment as completed.
     */
    public static function completeEnrollment(string $clientId, DripV2Enrollment $enrollment): void
    {
        $conn = "mysql_{$clientId}";

        $enrollment->status         = 'completed';
        $enrollment->completed_at   = Carbon::now();
        $enrollment->next_send_at   = null;
        $enrollment->save();

        $campaign = DripV2Campaign::on($conn)->find($enrollment->campaign_id);
        ActivityService::log(
            $clientId,
            $enrollment->lead_id,
            'drip_completed',
            "Completed campaign: " . ($campaign->name ?? "#{$enrollment->campaign_id}"),
            null,
            ['campaign_id' => $enrollment->campaign_id],
            0
        );
    }

    /**
     * Check exit conditions for an enrollment.
     * Returns true if the enrollment should be stopped.
     */
    public static function checkExitConditions(string $clientId, DripV2Enrollment $enrollment): bool
    {
        $conn = "mysql_{$clientId}";
        $campaign = DripV2Campaign::on($conn)->find($enrollment->campaign_id);

        if (!$campaign || empty($campaign->exit_conditions)) {
            return false;
        }

        $conditions = $campaign->exit_conditions;

        // Check unsubscribe
        if (!empty($conditions['unsubscribed'])) {
            $unsub = DripV2Unsubscribe::on($conn)
                ->where('lead_id', $enrollment->lead_id)
                ->whereIn('channel', [$campaign->channel, 'both'])
                ->first();
            if ($unsub) return true;
        }

        // Check lead status-based exits
        if (!empty($conditions['lead_statuses'])) {
            try {
                $lead = DB::connection($conn)->table('crm_leads')
                    ->where('id', $enrollment->lead_id)
                    ->first(['lead_status']);
                if ($lead && in_array($lead->lead_status, $conditions['lead_statuses'])) {
                    return true;
                }
            } catch (\Throwable $e) {
                // Non-fatal
            }
        }

        // Check bounced
        if (!empty($conditions['bounced'])) {
            $hasBounce = DB::connection($conn)->table('drip_v2_send_log')
                ->where('enrollment_id', $enrollment->id)
                ->where('status', 'bounced')
                ->exists();
            if ($hasBounce) return true;
        }

        return false;
    }

    /**
     * Process auto-enrollment triggers for a lead event.
     */
    public static function processAutoTriggers(string $clientId, int $leadId, string $triggerType, array $triggerData = []): void
    {
        $conn = "mysql_{$clientId}";

        $campaigns = DripV2Campaign::on($conn)
            ->where('status', 'active')
            ->whereNotNull('trigger_rules')
            ->get();

        foreach ($campaigns as $campaign) {
            $rules = $campaign->trigger_rules;
            if (!is_array($rules)) continue;

            foreach ($rules as $rule) {
                if (($rule['type'] ?? null) !== $triggerType) continue;

                // Match rule criteria
                $matched = true;
                if (!empty($rule['conditions'])) {
                    foreach ($rule['conditions'] as $key => $val) {
                        if (($triggerData[$key] ?? null) !== $val) {
                            $matched = false;
                            break;
                        }
                    }
                }

                if ($matched) {
                    try {
                        self::enroll($clientId, $leadId, $campaign->id, null, 'trigger', $triggerType);
                    } catch (\Throwable $e) {
                        Log::warning("Auto-trigger enroll failed", [
                            'client'   => $clientId,
                            'lead'     => $leadId,
                            'campaign' => $campaign->id,
                            'error'    => $e->getMessage(),
                        ]);
                    }
                }
            }
        }
    }

    /**
     * Get enrollments for a campaign.
     */
    public static function listEnrollments(string $clientId, int $campaignId, array $params = []): array
    {
        $conn = "mysql_{$clientId}";
        $query = DripV2Enrollment::on($conn)->where('campaign_id', $campaignId);

        if (!empty($params['status'])) {
            $query->where('status', $params['status']);
        }

        $perPage = (int) ($params['per_page'] ?? 25);
        $page    = (int) ($params['page'] ?? 1);

        $total = (clone $query)->count();
        $data  = $query->orderByDesc('id')
            ->skip(($page - 1) * $perPage)
            ->take($perPage)
            ->get();

        return [
            'data'     => $data,
            'total'    => $total,
            'page'     => $page,
            'per_page' => $perPage,
        ];
    }

    /**
     * Get a lead's enrollment history across all campaigns.
     */
    public static function leadEnrollments(string $clientId, int $leadId): array
    {
        $conn = "mysql_{$clientId}";
        return DripV2Enrollment::on($conn)
            ->where('lead_id', $leadId)
            ->with('campaign:id,name,status,channel')
            ->orderByDesc('id')
            ->get()
            ->toArray();
    }
}
