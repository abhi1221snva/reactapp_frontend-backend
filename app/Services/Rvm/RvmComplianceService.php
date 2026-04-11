<?php

namespace App\Services\Rvm;

use App\Model\Master\Rvm\Campaign;
use App\Model\Master\Rvm\Dnc;
use App\Model\Master\Rvm\Drop;
use App\Services\Rvm\Exceptions\DncBlockedException;
use App\Services\Rvm\Exceptions\QuietHoursException;
use App\Services\Rvm\Support\PhoneNormalizer;
use Carbon\Carbon;
use DateTimeZone;
use Illuminate\Support\Facades\DB;

/**
 * Centralised compliance gate.
 *
 * Evaluated at TWO points:
 *   1. At drop creation (RvmDropService::createDrop) — fast-fail the API caller
 *   2. At drop dispatch (ProcessRvmDropJob::handle)   — time may have shifted
 *      across retries, TZ could have rolled over, campaign may have paused
 */
class RvmComplianceService
{
    /**
     * Throws on violation; returns silently on pass.
     *
     * @param int    $clientId
     * @param string $phoneE164
     * @param bool   $respectDnc
     * @param bool   $respectQuietHours
     * @param string $quietStart  "HH:MM:SS"
     * @param string $quietEnd    "HH:MM:SS"
     */
    public function assertCompliant(
        int $clientId,
        string $phoneE164,
        bool $respectDnc = true,
        bool $respectQuietHours = true,
        string $quietStart = '09:00:00',
        string $quietEnd = '20:00:00',
    ): void {
        if ($respectDnc) {
            $this->assertNotDnc($clientId, $phoneE164);
        }
        if ($respectQuietHours && !$this->isWithinQuietHours($phoneE164, $quietStart, $quietEnd)) {
            throw new QuietHoursException("Outside calling window for {$phoneE164}");
        }
    }

    /**
     * Quick boolean: is the drop allowed to dispatch RIGHT NOW?
     * Used by the worker for the post-dequeue re-check.
     */
    public function windowOpen(Drop $drop): bool
    {
        // DNC re-check (cheap)
        if ($this->isDnc((int) $drop->client_id, $drop->phone_e164)) {
            return false;
        }

        // Resolve quiet hours from campaign if present, else defaults
        $start = '09:00:00';
        $end   = '20:00:00';
        if ($drop->campaign_id) {
            $campaign = Campaign::on('master')->find($drop->campaign_id);
            if ($campaign) {
                if ($campaign->status === 'paused') return false;
                $start = $campaign->quiet_start;
                $end   = $campaign->quiet_end;
            }
        }

        return $this->isWithinQuietHours($drop->phone_e164, $start, $end);
    }

    /**
     * Next time the quiet-hours window opens for a given phone.
     * Used to set deferred_until.
     */
    public function nextWindow(Drop $drop): Carbon
    {
        $tz = $this->timezoneForPhone($drop->phone_e164);
        $now = Carbon::now($tz);

        $start = '09:00:00';
        if ($drop->campaign_id) {
            $campaign = Campaign::on('master')->find($drop->campaign_id);
            if ($campaign) $start = $campaign->quiet_start;
        }

        [$h, $m, $s] = array_map('intval', explode(':', $start));
        $next = $now->copy()->setTime($h, $m, $s);
        if ($next->lte($now)) {
            $next->addDay();
        }

        return $next->utc();
    }

    public function isDnc(int $clientId, string $phoneE164): bool
    {
        return Dnc::on('master')
            ->where('phone_e164', $phoneE164)
            ->where(function ($q) use ($clientId) {
                $q->whereNull('client_id')->orWhere('client_id', $clientId);
            })
            ->exists();
    }

    private function assertNotDnc(int $clientId, string $phoneE164): void
    {
        if ($this->isDnc($clientId, $phoneE164)) {
            throw new DncBlockedException("Phone {$phoneE164} is on the DNC list");
        }
    }

    private function isWithinQuietHours(string $phoneE164, string $start, string $end): bool
    {
        $tz = $this->timezoneForPhone($phoneE164);
        $now = Carbon::now($tz);
        $startToday = Carbon::parse($now->toDateString() . ' ' . $start, $tz);
        $endToday = Carbon::parse($now->toDateString() . ' ' . $end, $tz);
        return $now->between($startToday, $endToday);
    }

    /**
     * Resolve the recipient timezone from the phone number.
     *
     * Primary path: NANP area code → master.timezone table (existing).
     * Fallback: UTC.
     */
    private function timezoneForPhone(string $phoneE164): DateTimeZone
    {
        $areaCode = PhoneNormalizer::nanpAreaCode($phoneE164);
        if (!$areaCode) return new DateTimeZone('UTC');

        $row = DB::connection('master')
            ->selectOne('SELECT timezone FROM timezone WHERE areacode = :ac', ['ac' => $areaCode]);

        if (!$row || empty($row->timezone)) return new DateTimeZone('UTC');

        $name = timezone_name_from_abbr($row->timezone) ?: 'UTC';
        return new DateTimeZone($name);
    }
}
