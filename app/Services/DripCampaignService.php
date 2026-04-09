<?php

namespace App\Services;

use App\Model\Client\DripV2Campaign;
use App\Model\Client\DripV2Step;
use App\Model\Client\DripV2Enrollment;
use App\Model\Client\DripV2SendLog;
use App\Model\Client\DripV2Event;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DripCampaignService
{
    /**
     * List campaigns with pagination and summary stats.
     */
    public static function list(string $clientId, array $params = []): array
    {
        $conn = "mysql_{$clientId}";
        $query = DripV2Campaign::on($conn);

        if (!empty($params['status'])) {
            $query->where('status', $params['status']);
        }

        if (!empty($params['search'])) {
            $query->where('name', 'like', '%' . $params['search'] . '%');
        }

        $perPage = (int) ($params['per_page'] ?? 25);
        $page    = (int) ($params['page'] ?? 1);

        // Count before applying pagination
        $total = (clone $query)->count();

        $campaigns = $query->withCount('steps')
            ->orderByDesc('id')
            ->skip(($page - 1) * $perPage)
            ->take($perPage)
            ->get();

        // Attach summary stats
        $campaigns->each(function ($c) use ($conn) {
            $c->stats = self::quickStats($conn, $c->id);
        });

        return [
            'data'     => $campaigns,
            'total'    => $total,
            'page'     => $page,
            'per_page' => $perPage,
        ];
    }

    /**
     * Create a campaign with steps.
     */
    public static function create(string $clientId, array $data, int $userId): DripV2Campaign
    {
        $conn = "mysql_{$clientId}";

        $campaign = new DripV2Campaign();
        $campaign->setConnection($conn);
        $campaign->fill([
            'name'              => $data['name'],
            'description'       => $data['description'] ?? null,
            'status'            => 'draft',
            'channel'           => $data['channel'] ?? 'email',
            'email_setting_id'  => $data['email_setting_id'] ?? null,
            'sms_from_number'   => $data['sms_from_number'] ?? null,
            'entry_conditions'  => $data['entry_conditions'] ?? null,
            'exit_conditions'   => $data['exit_conditions'] ?? null,
            'trigger_rules'     => $data['trigger_rules'] ?? null,
            'quiet_hours_start' => $data['quiet_hours_start'] ?? null,
            'quiet_hours_end'   => $data['quiet_hours_end'] ?? null,
            'quiet_hours_tz'    => $data['quiet_hours_tz'] ?? null,
            'created_by'        => $userId,
            'updated_by'        => $userId,
        ]);
        $campaign->save();

        if (!empty($data['steps'])) {
            self::syncSteps($conn, $campaign->id, $data['steps']);
        }

        return $campaign->load('steps');
    }

    /**
     * Update campaign + steps.
     */
    public static function update(string $clientId, int $campaignId, array $data, int $userId): DripV2Campaign
    {
        $conn = "mysql_{$clientId}";
        $campaign = DripV2Campaign::on($conn)->findOrFail($campaignId);

        $fillable = [
            'name', 'description', 'channel', 'email_setting_id', 'sms_from_number',
            'entry_conditions', 'exit_conditions', 'trigger_rules',
            'quiet_hours_start', 'quiet_hours_end', 'quiet_hours_tz',
        ];
        $updates = [];
        foreach ($fillable as $key) {
            if (array_key_exists($key, $data)) {
                $updates[$key] = $data[$key];
            }
        }
        $campaign->fill($updates);
        $campaign->updated_by = $userId;
        $campaign->save();

        if (isset($data['steps'])) {
            self::syncSteps($conn, $campaign->id, $data['steps']);
        }

        return $campaign->load('steps');
    }

    /**
     * Duplicate campaign + steps.
     */
    public static function duplicate(string $clientId, int $campaignId, int $userId): DripV2Campaign
    {
        $conn = "mysql_{$clientId}";
        $original = DripV2Campaign::on($conn)->with('steps')->findOrFail($campaignId);

        $clone = $original->replicate();
        $clone->setConnection($conn);
        $clone->name       = $original->name . ' (Copy)';
        $clone->status     = 'draft';
        $clone->created_by = $userId;
        $clone->updated_by = $userId;
        $clone->activated_at = null;
        $clone->archived_at = null;
        $clone->save();

        foreach ($original->steps as $step) {
            $newStep = $step->replicate();
            $newStep->setConnection($conn);
            $newStep->campaign_id = $clone->id;
            $newStep->save();
        }

        return $clone->load('steps');
    }

    /**
     * Activate campaign.
     */
    public static function activate(string $clientId, int $campaignId): DripV2Campaign
    {
        $conn = "mysql_{$clientId}";
        $campaign = DripV2Campaign::on($conn)->findOrFail($campaignId);

        // Require at least one active step
        $stepCount = DripV2Step::on($conn)
            ->where('campaign_id', $campaignId)
            ->where('is_active', true)
            ->count();

        if ($stepCount === 0) {
            throw new \Exception('Campaign must have at least one active step to activate.');
        }

        $campaign->status = 'active';
        $campaign->activated_at = Carbon::now();
        $campaign->save();

        return $campaign;
    }

    /**
     * Pause campaign — does NOT stop existing enrollments.
     */
    public static function pause(string $clientId, int $campaignId): DripV2Campaign
    {
        $conn = "mysql_{$clientId}";
        $campaign = DripV2Campaign::on($conn)->findOrFail($campaignId);
        $campaign->status = 'paused';
        $campaign->save();
        return $campaign;
    }

    /**
     * Archive campaign and stop all active enrollments.
     */
    public static function archive(string $clientId, int $campaignId): DripV2Campaign
    {
        $conn = "mysql_{$clientId}";
        $campaign = DripV2Campaign::on($conn)->findOrFail($campaignId);
        $campaign->status      = 'archived';
        $campaign->archived_at = Carbon::now();
        $campaign->save();

        // Stop all active enrollments
        DripV2Enrollment::on($conn)
            ->where('campaign_id', $campaignId)
            ->where('status', 'active')
            ->update([
                'status'         => 'stopped',
                'stopped_reason' => 'campaign_archived',
                'stopped_at'     => Carbon::now(),
            ]);

        return $campaign;
    }

    /**
     * Delete (archive) campaign.
     */
    public static function delete(string $clientId, int $campaignId): void
    {
        self::archive($clientId, $campaignId);
    }

    /**
     * Get campaign with steps and stats.
     */
    public static function show(string $clientId, int $campaignId): array
    {
        $conn = "mysql_{$clientId}";
        $campaign = DripV2Campaign::on($conn)->with('steps')->findOrFail($campaignId);
        $campaign->stats = self::campaignAnalytics($conn, $campaignId);
        return $campaign->toArray();
    }

    /**
     * Campaign-level analytics.
     */
    public static function campaignAnalytics(string $conn, int $campaignId): array
    {
        $enrollments = DripV2Enrollment::on($conn)->where('campaign_id', $campaignId);

        $totalEnrolled = (clone $enrollments)->count();
        $active        = (clone $enrollments)->where('status', 'active')->count();
        $completed     = (clone $enrollments)->where('status', 'completed')->count();
        $stopped       = (clone $enrollments)->where('status', 'stopped')->count();

        $logs = DripV2SendLog::on($conn)
            ->whereIn('enrollment_id', DripV2Enrollment::on($conn)
                ->where('campaign_id', $campaignId)
                ->pluck('id'));

        $emailsSent    = (clone $logs)->where('channel', 'email')->count();
        $emailsOpened  = (clone $logs)->where('channel', 'email')->whereNotNull('opened_at')->count();
        $emailsClicked = (clone $logs)->where('channel', 'email')->whereNotNull('clicked_at')->count();
        $emailsBounced = (clone $logs)->where('channel', 'email')->where('status', 'bounced')->count();
        $smsSent       = (clone $logs)->where('channel', 'sms')->count();
        $smsDelivered  = (clone $logs)->where('channel', 'sms')->where('status', 'delivered')->count();
        $smsFailed     = (clone $logs)->where('channel', 'sms')->where('status', 'failed')->count();

        return [
            'total_enrolled'  => $totalEnrolled,
            'active'          => $active,
            'completed'       => $completed,
            'stopped'         => $stopped,
            'emails_sent'     => $emailsSent,
            'emails_opened'   => $emailsOpened,
            'emails_clicked'  => $emailsClicked,
            'emails_bounced'  => $emailsBounced,
            'sms_sent'        => $smsSent,
            'sms_delivered'   => $smsDelivered,
            'sms_failed'      => $smsFailed,
        ];
    }

    /**
     * Per-step analytics.
     */
    public static function stepAnalytics(string $clientId, int $campaignId): array
    {
        $conn = "mysql_{$clientId}";
        $steps = DripV2Step::on($conn)
            ->where('campaign_id', $campaignId)
            ->orderBy('position')
            ->get();

        $enrollmentIds = DripV2Enrollment::on($conn)
            ->where('campaign_id', $campaignId)
            ->pluck('id');

        $result = [];
        foreach ($steps as $step) {
            $logs = DripV2SendLog::on($conn)
                ->where('step_id', $step->id)
                ->whereIn('enrollment_id', $enrollmentIds);

            $result[] = [
                'step_id'   => $step->id,
                'position'  => $step->position,
                'channel'   => $step->channel,
                'subject'   => $step->subject,
                'sent'      => (clone $logs)->count(),
                'delivered' => (clone $logs)->whereIn('status', ['delivered', 'opened', 'clicked'])->count(),
                'opened'    => (clone $logs)->whereNotNull('opened_at')->count(),
                'clicked'   => (clone $logs)->whereNotNull('clicked_at')->count(),
                'bounced'   => (clone $logs)->where('status', 'bounced')->count(),
                'failed'    => (clone $logs)->where('status', 'failed')->count(),
            ];
        }

        return $result;
    }

    // ─── Private helpers ──────────────────────────────────────────────────────

    /**
     * Sync steps: delete existing and re-insert in order.
     */
    private static function syncSteps(string $conn, int $campaignId, array $steps): void
    {
        DripV2Step::on($conn)->where('campaign_id', $campaignId)->delete();

        foreach ($steps as $i => $stepData) {
            $step = new DripV2Step();
            $step->setConnection($conn);
            $step->fill([
                'campaign_id'       => $campaignId,
                'position'          => $i + 1,
                'channel'           => $stepData['channel'] ?? 'email',
                'delay_value'       => $stepData['delay_value'] ?? 0,
                'delay_unit'        => $stepData['delay_unit'] ?? 'hours',
                'send_at_time'      => $stepData['send_at_time'] ?? null,
                'subject'           => $stepData['subject'] ?? null,
                'body_html'         => $stepData['body_html'] ?? null,
                'body_plain'        => $stepData['body_plain'] ?? null,
                'email_template_id' => $stepData['email_template_id'] ?? null,
                'sms_template_id'   => $stepData['sms_template_id'] ?? null,
                'is_active'         => $stepData['is_active'] ?? true,
            ]);
            $step->save();
        }
    }

    /**
     * Quick summary stats for campaign list.
     */
    private static function quickStats(string $conn, int $campaignId): array
    {
        $enrollmentIds = DripV2Enrollment::on($conn)
            ->where('campaign_id', $campaignId)
            ->pluck('id');

        return [
            'enrolled'     => $enrollmentIds->count(),
            'active'       => DripV2Enrollment::on($conn)->where('campaign_id', $campaignId)->where('status', 'active')->count(),
            'total_sent'   => $enrollmentIds->isEmpty() ? 0 : DripV2SendLog::on($conn)->whereIn('enrollment_id', $enrollmentIds)->count(),
            'total_opened' => $enrollmentIds->isEmpty() ? 0 : DripV2SendLog::on($conn)->whereIn('enrollment_id', $enrollmentIds)->whereNotNull('opened_at')->count(),
        ];
    }
}
