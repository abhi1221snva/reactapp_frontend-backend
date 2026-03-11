<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Lead Scoring Service
 *
 * Computes a quality score (0–100) for a CRM lead based on:
 *   - Field completeness     (max 25 pts)
 *   - Recent activity        (max 25 pts)
 *   - Stage progression speed (max 25 pts)
 *   - Engagement signals     (max 25 pts: documents, tasks, approvals)
 *
 * Call score() to get the current score, or recalculate() to persist it.
 */
class LeadScoringService
{
    private int $clientId;

    public function __construct(int $clientId)
    {
        $this->clientId = $clientId;
    }

    public static function forClient(int $clientId): self
    {
        return new self($clientId);
    }

    private function db()
    {
        return DB::connection('mysql_' . $this->clientId);
    }

    // ─── Public API ───────────────────────────────────────────────────────────

    /**
     * Compute and persist the score for a single lead.
     * Returns the new score (0–100).
     */
    public function recalculate(int $leadId): int
    {
        $lead = $this->db()->table('crm_lead_data')->where('id', $leadId)->first();
        if (!$lead) return 0;

        $score = $this->compute($lead);

        $this->db()->table('crm_lead_data')
            ->where('id', $leadId)
            ->update(['score' => $score]);

        return $score;
    }

    /**
     * Compute the score without persisting (read-only).
     */
    public function score(int $leadId): int
    {
        $lead = $this->db()->table('crm_lead_data')->where('id', $leadId)->first();
        return $lead ? $this->compute($lead) : 0;
    }

    /**
     * Recalculate scores for all active leads in a campaign or entire client DB.
     * Returns count of leads updated.
     */
    public function recalculateBatch(?int $campaignId = null): int
    {
        $query = $this->db()->table('crm_lead_data')->where('is_deleted', 0);

        $leads   = $query->get();
        $updated = 0;

        foreach ($leads as $lead) {
            $score = $this->compute($lead);
            $this->db()->table('crm_lead_data')
                ->where('id', $lead->id)
                ->update(['score' => $score]);
            $updated++;
        }

        return $updated;
    }

    // ─── Scoring components ───────────────────────────────────────────────────

    private function compute(object $lead): int
    {
        $score = 0;
        $score += $this->fieldCompletenessScore($lead);   // 0–25
        $score += $this->recentActivityScore($lead->id);   // 0–25
        $score += $this->stageProgressionScore($lead->id); // 0–25
        $score += $this->engagementScore($lead->id);       // 0–25
        return min(100, max(0, $score));
    }

    /**
     * Score based on how many key fields are filled (max 25).
     */
    private function fieldCompletenessScore(object $lead): int
    {
        $fields = [
            'first_name', 'last_name', 'email', 'phone_number',
            'company_name', 'address', 'city', 'state',
        ];
        $filled = 0;
        foreach ($fields as $f) {
            if (!empty($lead->$f)) $filled++;
        }
        return (int) round(($filled / count($fields)) * 25);
    }

    /**
     * Score based on recency of last activity (max 25).
     * < 1 day = 25, < 3 days = 20, < 7 days = 15, < 14 days = 10, < 30 days = 5, else = 0
     */
    private function recentActivityScore(int $leadId): int
    {
        $latest = $this->db()->table('crm_lead_activity')
            ->where('lead_id', $leadId)
            ->max('created_at');

        if (!$latest) return 0;

        $days = now()->diffInDays($latest);

        return match (true) {
            $days < 1  => 25,
            $days < 3  => 20,
            $days < 7  => 15,
            $days < 14 => 10,
            $days < 30 => 5,
            default    => 0,
        };
    }

    /**
     * Score based on how many status changes the lead has gone through (max 25).
     * Each stage = 5 pts, up to 5 stages.
     */
    private function stageProgressionScore(int $leadId): int
    {
        $changes = $this->db()->table('crm_lead_status_history')
            ->where('lead_id', $leadId)
            ->count();
        return min(25, $changes * 5);
    }

    /**
     * Score based on engagement signals: documents, tasks, approved approvals (max 25).
     * documents >=1: +10, tasks completed >=1: +10, approved approval: +5
     */
    private function engagementScore(int $leadId): int
    {
        $pts = 0;

        $hasDocs = $this->db()->table('crm_documents')->where('lead_id', $leadId)->exists();
        if ($hasDocs) $pts += 10;

        $hasTasks = $this->db()->table('crm_scheduled_task')
            ->where('lead_id', $leadId)
            ->where('is_sent', 1)
            ->exists();
        if ($hasTasks) $pts += 10;

        $hasApproval = $this->db()->table('crm_lead_approvals')
            ->where('lead_id', $leadId)
            ->where('status', 'approved')
            ->exists();
        if ($hasApproval) $pts += 5;

        return min(25, $pts);
    }
}
