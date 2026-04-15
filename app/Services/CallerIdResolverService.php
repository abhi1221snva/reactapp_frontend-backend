<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Resolves the outbound Caller ID for a campaign call based on the campaign's
 * caller_id strategy setting and the lead's phone number.
 *
 * Strategies (campaign.caller_id ENUM):
 *
 *  custom           — use campaign.custom_caller_id verbatim
 *  area_code        — match a DID from the `did` table whose area_code equals
 *                     the lead's 3-digit area code; fallback: same-state DIDs;
 *                     fallback: any available DID
 *  area_code_random — match area code first; fallback: any random DID
 *  area_code_3      — mirror first 6 digits of lead's number, randomise last 4
 *  area_code_4      — mirror first 7 digits, randomise last 3
 *  area_code_5      — mirror first 8 digits, randomise last 2
 *
 * This service is a clean extraction of the logic already present in
 * DialerController and Asterisk.php, so it can be reused by CampaignDialerService.
 */
class CallerIdResolverService
{
    /**
     * Resolve the outbound CLI for a campaign call.
     *
     * @param  string  $dbConnection   Tenant DB connection name (e.g. "client_1")
     * @param  int     $campaignId     Campaign ID (reads caller_id + custom_caller_id)
     * @param  string  $leadPhone      Lead's phone number (digits only, e.g. "4155551234")
     * @return string|null             E.164-ish number string, or null if none found
     */
    public function resolve(string $dbConnection, int $campaignId, string $leadPhone): ?string
    {
        $leadPhone = preg_replace('/\D/', '', $leadPhone);

        if (empty($leadPhone)) {
            return null;
        }

        // Load campaign's caller_id settings
        $campaign = DB::connection($dbConnection)
            ->table('campaign')
            ->where('id', $campaignId)
            ->select('caller_id', 'custom_caller_id')
            ->first();

        if (!$campaign) {
            Log::warning("CallerIdResolver: campaign {$campaignId} not found in {$dbConnection}");
            return null;
        }

        $strategy = $campaign->caller_id ?? 'area_code';

        switch ($strategy) {
            case 'custom':
                return $this->resolveCustom($campaign);

            case 'area_code':
                return $this->resolveAreaCode($dbConnection, $leadPhone);

            case 'area_code_random':
                return $this->resolveAreaCodeRandom($dbConnection, $leadPhone);

            case 'area_code_3':
                return $this->resolveAreaCodeN($leadPhone, 6, 4); // mirror 6 digits, random 4

            case 'area_code_4':
                return $this->resolveAreaCodeN($leadPhone, 7, 3); // mirror 7 digits, random 3

            case 'area_code_5':
                return $this->resolveAreaCodeN($leadPhone, 8, 2); // mirror 8 digits, random 2

            default:
                Log::warning("CallerIdResolver: unknown strategy '{$strategy}', falling back to any DID");
                return $this->anyDid($dbConnection);
        }
    }

    // -------------------------------------------------------------------------
    // Strategy implementations
    // -------------------------------------------------------------------------

    /**
     * custom: return the campaign's fixed custom_caller_id.
     */
    protected function resolveCustom(object $campaign): ?string
    {
        if (empty($campaign->custom_caller_id)) {
            return null;
        }
        return (string) $campaign->custom_caller_id;
    }

    /**
     * area_code: try exact 3-digit area code match → same-state DIDs → any DID.
     *
     * This mirrors the logic in DialerController::outboundAIDial() and Asterisk.php.
     */
    protected function resolveAreaCode(string $dbConnection, string $leadPhone): ?string
    {
        $areaCode = substr($leadPhone, 0, 3);

        // 1. Exact area code match
        $did = $this->didByAreaCode($dbConnection, $areaCode);
        if ($did) return $did;

        // 2. Same-state fallback — look up other area codes in the same state
        $stateAreaCodes = $this->sameStateAreaCodes($areaCode);
        if (!empty($stateAreaCodes)) {
            $did = $this->didByAreaCodes($dbConnection, $stateAreaCodes);
            if ($did) return $did;
        }

        // 3. Any available DID
        return $this->anyDid($dbConnection);
    }

    /**
     * area_code_random: try exact area code match → any DID (no same-state step).
     */
    protected function resolveAreaCodeRandom(string $dbConnection, string $leadPhone): ?string
    {
        $areaCode = substr($leadPhone, 0, 3);

        $did = $this->didByAreaCode($dbConnection, $areaCode);
        if ($did) return $did;

        return $this->anyDid($dbConnection);
    }

    /**
     * area_code_3/4/5: mirror the first N digits of the lead's number, append
     * random digits to fill the remaining positions to 10 digits total.
     *
     * e.g. area_code_3: lead=4155551234 → mirror "415555" (6 digits) + rand(1111-9999)
     *
     * @param  int  $mirrorLen   Number of digits to copy from the lead's number
     * @param  int  $randDigits  Number of random digits to append
     */
    protected function resolveAreaCodeN(string $leadPhone, int $mirrorLen, int $randDigits): string
    {
        $prefix = substr($leadPhone, 0, $mirrorLen);
        $min    = (int) str_pad('1', $randDigits, '1');
        $max    = (int) str_pad('9', $randDigits, '9');
        $suffix = (string) rand($min, $max);

        return $prefix . $suffix;
    }

    // -------------------------------------------------------------------------
    // DID table helpers
    // -------------------------------------------------------------------------

    /**
     * Pick a random non-exclusive, non-deleted DID matching a single area code.
     */
    protected function didByAreaCode(string $dbConnection, string $areaCode): ?string
    {
        $row = DB::connection($dbConnection)
            ->table('did')
            ->where('area_code', $areaCode)
            ->where('set_exclusive_for_user', '0')
            ->where('is_deleted', '0')
            ->inRandomOrder()
            ->value('cli');

        return $row ? (string) $row : null;
    }

    /**
     * Pick a random DID from a set of area codes.
     *
     * @param  string[]  $areaCodes
     */
    protected function didByAreaCodes(string $dbConnection, array $areaCodes): ?string
    {
        if (empty($areaCodes)) return null;

        $row = DB::connection($dbConnection)
            ->table('did')
            ->whereIn('area_code', $areaCodes)
            ->where('set_exclusive_for_user', '0')
            ->where('is_deleted', '0')
            ->inRandomOrder()
            ->value('cli');

        return $row ? (string) $row : null;
    }

    /**
     * Last-resort: pick any available DID at random.
     */
    protected function anyDid(string $dbConnection): ?string
    {
        $row = DB::connection($dbConnection)
            ->table('did')
            ->where('set_exclusive_for_user', '0')
            ->where('is_deleted', '0')
            ->inRandomOrder()
            ->value('cli');

        return $row ? (string) $row : null;
    }

    // -------------------------------------------------------------------------
    // AreaCodeList helper
    // -------------------------------------------------------------------------

    /**
     * Return all area codes in the same US state as $areaCode, excluding itself.
     * Uses the area_code_list table (via AreaCodeList model).
     *
     * @return string[]
     */
    protected function sameStateAreaCodes(string $areaCode): array
    {
        // AreaCodeList is a master-DB model — access via raw query on 'master' connection
        $state = DB::connection('master')
            ->table('area_code_list')
            ->where('areacode', $areaCode)
            ->value('state_code');

        if (!$state) {
            return [];
        }

        return DB::connection('master')
            ->table('area_code_list')
            ->where('state_code', $state)
            ->where('areacode', '!=', $areaCode)
            ->pluck('areacode')
            ->toArray();
    }
}
