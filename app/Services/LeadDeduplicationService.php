<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Lead Deduplication Service
 *
 * Detects duplicate leads by phone number (primary) or email (secondary).
 *
 * Policies:
 *   block  — Reject duplicates entirely (don't import)
 *   flag   — Import but mark as potential duplicate
 *   merge  — Import; caller should merge fields into original
 */
class LeadDeduplicationService
{
    const POLICY_BLOCK = 'block';
    const POLICY_FLAG  = 'flag';
    const POLICY_MERGE = 'merge';

    private int    $clientId;
    private string $policy;

    public function __construct(int $clientId, string $policy = self::POLICY_FLAG)
    {
        $this->clientId = $clientId;
        $this->policy   = $policy;
    }

    public static function forClient(int $clientId, string $policy = self::POLICY_FLAG): self
    {
        return new self($clientId, $policy);
    }

    private function db()
    {
        return DB::connection('mysql_' . $this->clientId);
    }

    // ─── Phone normalization ─────────────────────────────────────────────────────

    /**
     * Strip non-digits and remove leading country code for North America.
     */
    public function normalizePhone(string $phone): string
    {
        $digits = preg_replace('/\D/', '', $phone);

        // Strip leading 1 for 11-digit NANP numbers
        if (strlen($digits) === 11 && $digits[0] === '1') {
            $digits = substr($digits, 1);
        }

        return $digits;
    }

    // ─── Single-lead checks ──────────────────────────────────────────────────────

    /**
     * Check if a phone number already exists in list_data.
     * Returns the existing lead_id or null.
     */
    public function findDuplicateByPhone(string $phone, int $excludeLeadId = 0): ?int
    {
        $norm = $this->normalizePhone($phone);
        if (strlen($norm) < 7) return null;

        $query = $this->db()->table('list_data')
            ->where(function ($q) use ($norm, $phone) {
                $q->whereRaw(
                    "REGEXP_REPLACE(phone, '[^0-9]', '') = ?",
                    [$norm]
                )->orWhere('phone', $phone);
            })
            ->where('status', '!=', 'deleted');

        if ($excludeLeadId > 0) {
            $query->where('id', '!=', $excludeLeadId);
        }

        $id = $query->value('id');
        return $id ? (int) $id : null;
    }

    // ─── Batch import dedup ──────────────────────────────────────────────────────

    /**
     * Find which phone numbers in a batch already exist.
     * Returns: [normalizedPhone => existingLeadId]
     */
    public function findDuplicatePhones(array $phones): array
    {
        if (empty($phones)) return [];

        $normalizedMap = [];
        foreach ($phones as $phone) {
            $norm = $this->normalizePhone((string) $phone);
            if (strlen($norm) >= 7) {
                $normalizedMap[$norm] = $phone;
            }
        }

        if (empty($normalizedMap)) return [];

        $existing = $this->db()->table('list_data')
            ->whereIn(DB::raw("REGEXP_REPLACE(phone, '[^0-9]', '')"), array_keys($normalizedMap))
            ->where('status', '!=', 'deleted')
            ->select(['id', 'phone'])
            ->get();

        $result = [];
        foreach ($existing as $row) {
            $norm = $this->normalizePhone($row->phone);
            if (isset($normalizedMap[$norm])) {
                $result[$normalizedMap[$norm]] = (int) $row->id;
            }
        }

        return $result;
    }

    /**
     * Process a batch of leads for import, applying the dedup policy.
     *
     * @param array  $leads       Array of lead arrays with phone field
     * @param string $phoneField  Name of the phone field in each lead array
     * @return array{passed: array, duplicates: array, stats: array}
     */
    public function processBatch(array $leads, string $phoneField = 'phone'): array
    {
        if (empty($leads)) {
            return ['passed' => [], 'duplicates' => [], 'stats' => ['total' => 0, 'duplicates' => 0, 'passed' => 0]];
        }

        $phones  = array_column($leads, $phoneField);
        $dupeMap = $this->findDuplicatePhones($phones);

        $passed     = [];
        $duplicates = [];

        foreach ($leads as $lead) {
            $phone      = (string) ($lead[$phoneField] ?? '');
            $existingId = $dupeMap[$phone] ?? null;

            if ($existingId !== null) {
                $duplicates[] = array_merge($lead, [
                    '_dedup_original_id' => $existingId,
                    '_dedup_action'      => $this->policy,
                ]);

                if ($this->policy !== self::POLICY_BLOCK) {
                    $passed[] = array_merge($lead, [
                        '_is_duplicate' => true,
                        '_original_id'  => $existingId,
                    ]);
                }
            } else {
                $passed[] = $lead;
            }
        }

        return [
            'passed'     => $passed,
            'duplicates' => $duplicates,
            'stats'      => [
                'total'      => count($leads),
                'duplicates' => count($duplicates),
                'passed'     => count($passed),
                'policy'     => $this->policy,
            ],
        ];
    }

    // ─── List scan ───────────────────────────────────────────────────────────────

    /**
     * Scan an existing list for internal duplicates (same phone appears multiple times).
     *
     * @param int $listId
     * @param int $limit  Max duplicate groups to return
     */
    public function scanListForDuplicates(int $listId, int $limit = 500): array
    {
        $rows = $this->db()->table('list_data')
            ->where('list_id', $listId)
            ->where('status', '!=', 'deleted')
            ->select(['id', 'phone'])
            ->limit(10000)
            ->get();

        $phoneMap = [];
        foreach ($rows as $row) {
            $norm = $this->normalizePhone((string) $row->phone);
            if ($norm && strlen($norm) >= 7) {
                $phoneMap[$norm][] = (int) $row->id;
            }
        }

        $duplicateGroups = array_filter($phoneMap, fn($ids) => count($ids) > 1);

        $groups = [];
        foreach ($duplicateGroups as $phone => $ids) {
            $groups[] = [
                'phone'       => $phone,
                'count'       => count($ids),
                'lead_ids'    => $ids,
                'original_id' => min($ids), // keep the oldest (lowest ID)
                'duplicate_ids' => array_values(array_diff($ids, [min($ids)])),
            ];
        }

        usort($groups, fn($a, $b) => $b['count'] - $a['count']);
        $groups = array_slice($groups, 0, $limit);

        $duplicateLeadCount = array_sum(array_map(fn($g) => $g['count'] - 1, $groups));

        return [
            'list_id'          => $listId,
            'total_leads'      => $rows->count(),
            'duplicate_groups' => $groups,
            'groups_count'     => count($groups),
            'duplicate_leads'  => $duplicateLeadCount,
        ];
    }

    /**
     * Log a detected duplicate to lead_dedup_log.
     */
    public function logDuplicate(
        int    $originalId,
        int    $duplicateId,
        string $matchField,
        string $matchValue,
        string $action = 'flagged',
        int    $detectedBy = 0
    ): void {
        try {
            $this->db()->table('lead_dedup_log')->insert([
                'original_lead_id'  => $originalId,
                'duplicate_lead_id' => $duplicateId,
                'match_field'       => $matchField,
                'match_value'       => substr($matchValue, 0, 255),
                'action'            => $action,
                'detected_by'       => $detectedBy ?: null,
                'created_at'        => now(),
                'updated_at'        => now(),
            ]);
        } catch (\Exception $e) {
            Log::warning("LeadDedup: Failed to log duplicate — {$e->getMessage()}");
        }
    }
}
