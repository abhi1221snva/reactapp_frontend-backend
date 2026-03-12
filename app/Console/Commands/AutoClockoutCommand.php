<?php

namespace App\Console\Commands;

use App\Model\Client\AgentStatus;
use App\Model\Client\Attendance;
use App\Model\Client\Shift;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Auto clock-out agents whose shift ended but they forgot to clock out.
 *
 * Runs every 15 minutes via scheduler.
 * Usage: php artisan workforce:auto-clockout
 */
class AutoClockoutCommand extends Command
{
    protected $signature   = 'workforce:auto-clockout {--dry-run : Preview without making changes}';
    protected $description = 'Auto clock-out agents who have not clocked out after their shift ended';

    public function handle(): void
    {
        $dryRun = $this->option('dry-run');
        $now    = Carbon::now();
        $today  = $now->toDateString();
        $graceMinutes = 30; // Give agents 30 min after shift ends before auto clock-out

        $this->info("Running auto clock-out check at {$now->format('H:i')} (dry-run: " . ($dryRun ? 'yes' : 'no') . ')');

        // Find all active attendances (clocked in, not clocked out) for today
        $activeAttendances = Attendance::where('date', $today)
            ->whereNotNull('clock_in_at')
            ->whereNull('clock_out_at')
            ->with('shift')
            ->get();

        if ($activeAttendances->isEmpty()) {
            $this->info('No active attendances found.');
            return;
        }

        $this->info("Found {$activeAttendances->count()} active attendance(s).");

        foreach ($activeAttendances as $attendance) {
            $shift = $attendance->shift;

            if (!$shift) {
                // No shift assigned: skip (can't determine expected end time without shift)
                continue;
            }

            $shiftEnd  = Carbon::parse($today . ' ' . $shift->end_time);
            $autoClockTime = $shiftEnd->copy()->addMinutes($graceMinutes);

            if ($now->lt($autoClockTime)) {
                // Shift hasn't exceeded grace period yet
                continue;
            }

            $this->info("Auto clocking out user_id={$attendance->user_id} (shift ended at {$shift->end_time})");

            if ($dryRun) {
                continue;
            }

            try {
                // Perform clock-out calculation
                $clockIn     = Carbon::parse($attendance->clock_in_at);
                $totalHours  = $clockIn->diffInMinutes($shiftEnd) / 60; // cap at shift end
                $breakHours  = $attendance->breaks()->sum('duration_minutes') / 60;
                $workHours   = max(0, $totalHours - $breakHours);

                $attendance->clock_out_at           = $shiftEnd; // clock out AT shift end, not now
                $attendance->total_hours            = round($workHours, 2);
                $attendance->break_hours            = round($breakHours, 2);
                $attendance->is_early_departure     = false;
                $attendance->notes                  = ($attendance->notes ? $attendance->notes . ' | ' : '')
                    . 'Auto clocked-out by system at ' . $now->format('H:i');
                $attendance->save();

                // Update dialer status to offline
                AgentStatus::setStatus($attendance->user_id, AgentStatus::OFFLINE);

                // End any active break
                $activeBreak = $attendance->breaks()->whereNull('break_end_at')->first();
                if ($activeBreak) {
                    $activeBreak->break_end_at    = $shiftEnd;
                    $activeBreak->duration_minutes = Carbon::parse($activeBreak->break_start_at)->diffInMinutes($shiftEnd);
                    $activeBreak->save();
                }

                Log::info('workforce:auto-clockout', [
                    'user_id'    => $attendance->user_id,
                    'date'       => $today,
                    'shift_end'  => $shift->end_time,
                    'clocked_at' => $shiftEnd->toDateTimeString(),
                ]);

                $this->info("  ✓ User {$attendance->user_id} auto clocked out.");

            } catch (\Exception $e) {
                Log::error('workforce:auto-clockout error', ['user_id' => $attendance->user_id, 'error' => $e->getMessage()]);
                $this->error("  ✗ Failed for user {$attendance->user_id}: " . $e->getMessage());
            }
        }

        $this->info('Auto clock-out check complete.');
    }
}
