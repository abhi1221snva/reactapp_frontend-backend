<?php

namespace App\Model\Client;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class AttendanceBreak extends Model
{
    protected $table = 'attendance_breaks';

    protected $fillable = [
        'attendance_id',
        'break_start_at',
        'break_end_at',
        'duration_minutes',
        'break_type',
        'notes'
    ];

    protected $casts = [
        'break_start_at' => 'datetime',
        'break_end_at' => 'datetime'
    ];

    const TYPE_LUNCH = 'lunch';
    const TYPE_SHORT = 'short';
    const TYPE_PERSONAL = 'personal';
    const TYPE_OTHER = 'other';

    public function attendance()
    {
        return $this->belongsTo(Attendance::class, 'attendance_id');
    }

    public function startBreak($request, $userId)
    {
        try {
            $today = Carbon::today()->toDateString();
            $now = Carbon::now();

            $attendance = Attendance::where('user_id', $userId)
                                    ->where('date', $today)
                                    ->first();

            if (!$attendance || !$attendance->clock_in_at) {
                return [
                    'success' => 'false',
                    'message' => 'You must clock in before starting a break.',
                    'data' => []
                ];
            }

            if ($attendance->clock_out_at) {
                return [
                    'success' => 'false',
                    'message' => 'You have already clocked out for today.',
                    'data' => []
                ];
            }

            $activeBreak = self::where('attendance_id', $attendance->id)
                               ->whereNull('break_end_at')
                               ->first();

            if ($activeBreak) {
                return [
                    'success' => 'false',
                    'message' => 'You are already on a break.',
                    'data' => $activeBreak
                ];
            }

            $break = new self();
            $break->attendance_id = $attendance->id;
            $break->break_start_at = $now;
            $break->break_type = $request->input('break_type', self::TYPE_SHORT);
            $break->notes = $request->input('notes');
            $break->save();

            return [
                'success' => 'true',
                'message' => 'Break started.',
                'data' => $break
            ];
        } catch (\Exception $e) {
            return [
                'success' => 'false',
                'message' => 'Error starting break: ' . $e->getMessage(),
                'data' => []
            ];
        }
    }

    public function endBreak($request, $userId)
    {
        try {
            $today = Carbon::today()->toDateString();
            $now = Carbon::now();

            $attendance = Attendance::where('user_id', $userId)
                                    ->where('date', $today)
                                    ->first();

            if (!$attendance) {
                return [
                    'success' => 'false',
                    'message' => 'No attendance record found for today.',
                    'data' => []
                ];
            }

            $activeBreak = self::where('attendance_id', $attendance->id)
                               ->whereNull('break_end_at')
                               ->first();

            if (!$activeBreak) {
                return [
                    'success' => 'false',
                    'message' => 'No active break found.',
                    'data' => []
                ];
            }

            $breakStart = Carbon::parse($activeBreak->break_start_at);
            $durationMinutes = $breakStart->diffInMinutes($now);

            $activeBreak->break_end_at = $now;
            $activeBreak->duration_minutes = $durationMinutes;
            $activeBreak->save();

            $totalBreakMinutes = self::where('attendance_id', $attendance->id)->sum('duration_minutes');
            $attendance->break_hours = round($totalBreakMinutes / 60, 2);
            $attendance->save();

            return [
                'success' => 'true',
                'message' => 'Break ended. Duration: ' . $durationMinutes . ' minutes.',
                'data' => $activeBreak,
                'duration_minutes' => $durationMinutes
            ];
        } catch (\Exception $e) {
            return [
                'success' => 'false',
                'message' => 'Error ending break: ' . $e->getMessage(),
                'data' => []
            ];
        }
    }

    public function getBreakHistory($attendanceId)
    {
        try {
            $breaks = self::where('attendance_id', $attendanceId)
                          ->orderBy('break_start_at', 'desc')
                          ->get();

            return [
                'success' => 'true',
                'message' => 'Break history retrieved.',
                'data' => $breaks,
                'total' => $breaks->count()
            ];
        } catch (\Exception $e) {
            return [
                'success' => 'false',
                'message' => 'Error retrieving breaks: ' . $e->getMessage(),
                'data' => []
            ];
        }
    }
}
