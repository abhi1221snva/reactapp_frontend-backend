<?php

namespace App\Model\Client;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class Attendance extends Model
{
    protected $table = 'attendances';

    protected $fillable = [
        'user_id',
        'shift_id',
        'date',
        'clock_in_at',
        'clock_out_at',
        'clock_in_ip',
        'clock_out_ip',
        'total_hours',
        'break_hours',
        'overtime_hours',
        'status',
        'notes',
        'is_late',
        'late_minutes',
        'is_early_departure',
        'early_departure_minutes'
    ];

    protected $casts = [
        'date' => 'date',
        'clock_in_at' => 'datetime',
        'clock_out_at' => 'datetime',
        'is_late' => 'boolean',
        'is_early_departure' => 'boolean'
    ];

    const STATUS_PRESENT = 'present';
    const STATUS_ABSENT = 'absent';
    const STATUS_LATE = 'late';
    const STATUS_EARLY_DEPARTURE = 'early_departure';
    const STATUS_HALF_DAY = 'half_day';
    const STATUS_ON_LEAVE = 'on_leave';

    public function shift()
    {
        return $this->belongsTo(Shift::class, 'shift_id');
    }

    public function breaks()
    {
        return $this->hasMany(AttendanceBreak::class, 'attendance_id');
    }

    public function clockIn($request, $userId)
    {
        try {
            $today = Carbon::today()->toDateString();
            $now = Carbon::now();
            $ip = $request->ip();

            $existingAttendance = self::where('user_id', $userId)
                                      ->where('date', $today)
                                      ->first();

            if ($existingAttendance && $existingAttendance->clock_in_at) {
                return [
                    'success' => 'false',
                    'message' => 'Already clocked in for today.',
                    'data' => $existingAttendance
                ];
            }

            $shiftModel = new Shift();
            $shift = $shiftModel->getUserShift($userId);

            $isLate = false;
            $lateMinutes = 0;

            if ($shift) {
                $shiftStart = Carbon::parse($today . ' ' . $shift->start_time);
                $graceEnd = $shiftStart->copy()->addMinutes($shift->grace_period_minutes);

                if ($now->gt($graceEnd)) {
                    $isLate = true;
                    $lateMinutes = $now->diffInMinutes($shiftStart);
                }
            }

            $attendance = $existingAttendance ?? new self();
            $attendance->user_id = $userId;
            $attendance->shift_id = $shift ? $shift->id : null;
            $attendance->date = $today;
            $attendance->clock_in_at = $now;
            $attendance->clock_in_ip = $ip;
            $attendance->is_late = $isLate;
            $attendance->late_minutes = $lateMinutes;
            $attendance->status = $isLate ? self::STATUS_LATE : self::STATUS_PRESENT;
            $attendance->save();

            return [
                'success' => 'true',
                'message' => $isLate ? 'Clocked in (Late by ' . $lateMinutes . ' minutes).' : 'Clocked in successfully.',
                'data' => $attendance,
                'is_late' => $isLate,
                'late_minutes' => $lateMinutes
            ];
        } catch (\Exception $e) {
            return [
                'success' => 'false',
                'message' => 'Error clocking in: ' . $e->getMessage(),
                'data' => []
            ];
        }
    }

    public function clockOut($request, $userId)
    {
        try {
            $today = Carbon::today()->toDateString();
            $now = Carbon::now();
            $ip = $request->ip();

            $attendance = self::where('user_id', $userId)
                              ->where('date', $today)
                              ->first();

            if (!$attendance || !$attendance->clock_in_at) {
                return [
                    'success' => 'false',
                    'message' => 'You have not clocked in today.',
                    'data' => []
                ];
            }

            if ($attendance->clock_out_at) {
                return [
                    'success' => 'false',
                    'message' => 'Already clocked out for today.',
                    'data' => $attendance
                ];
            }

            $shift = $attendance->shift;
            $isEarlyDeparture = false;
            $earlyDepartureMinutes = 0;

            if ($shift) {
                $shiftEnd = Carbon::parse($today . ' ' . $shift->end_time);
                $earlyEnd = $shiftEnd->copy()->subMinutes($shift->early_departure_minutes);

                if ($now->lt($earlyEnd)) {
                    $isEarlyDeparture = true;
                    $earlyDepartureMinutes = $shiftEnd->diffInMinutes($now);
                }
            }

            $clockIn = Carbon::parse($attendance->clock_in_at);
            $totalHours = $clockIn->diffInMinutes($now) / 60;
            $breakHours = $attendance->breaks()->sum('duration_minutes') / 60;
            $workingHours = $totalHours - $breakHours;

            $overtimeHours = 0;
            if ($shift) {
                $shiftStart = Carbon::parse($today . ' ' . $shift->start_time);
                $shiftEnd = Carbon::parse($today . ' ' . $shift->end_time);
                $expectedHours = $shiftStart->diffInMinutes($shiftEnd) / 60;

                if ($workingHours > $expectedHours) {
                    $overtimeHours = $workingHours - $expectedHours;
                }
            }

            $attendance->clock_out_at = $now;
            $attendance->clock_out_ip = $ip;
            $attendance->total_hours = round($workingHours, 2);
            $attendance->break_hours = round($breakHours, 2);
            $attendance->overtime_hours = round($overtimeHours, 2);
            $attendance->is_early_departure = $isEarlyDeparture;
            $attendance->early_departure_minutes = $earlyDepartureMinutes;

            if ($isEarlyDeparture && !$attendance->is_late) {
                $attendance->status = self::STATUS_EARLY_DEPARTURE;
            } elseif ($attendance->is_late && $isEarlyDeparture) {
                $attendance->status = self::STATUS_HALF_DAY;
            }

            $attendance->save();

            return [
                'success' => 'true',
                'message' => $isEarlyDeparture ? 'Clocked out (Early by ' . $earlyDepartureMinutes . ' minutes).' : 'Clocked out successfully.',
                'data' => $attendance,
                'total_hours' => $attendance->total_hours,
                'is_early_departure' => $isEarlyDeparture,
                'early_departure_minutes' => $earlyDepartureMinutes
            ];
        } catch (\Exception $e) {
            return [
                'success' => 'false',
                'message' => 'Error clocking out: ' . $e->getMessage(),
                'data' => []
            ];
        }
    }

    public function getAttendanceStatus($userId)
    {
        try {
            $today = Carbon::today()->toDateString();

            $attendance = self::where('user_id', $userId)
                              ->where('date', $today)
                              ->with(['shift', 'breaks'])
                              ->first();

            $isClockedIn = $attendance && $attendance->clock_in_at && !$attendance->clock_out_at;
            $isOnBreak = false;

            if ($isClockedIn) {
                $activeBreak = $attendance->breaks()
                    ->whereNull('break_end_at')
                    ->first();
                $isOnBreak = $activeBreak !== null;
            }

            return [
                'success' => 'true',
                'message' => 'Attendance status retrieved.',
                'data' => [
                    'attendance' => $attendance,
                    'is_clocked_in' => $isClockedIn,
                    'is_on_break' => $isOnBreak,
                    'clock_in_at' => $attendance ? $attendance->clock_in_at : null,
                    'clock_out_at' => $attendance ? $attendance->clock_out_at : null
                ]
            ];
        } catch (\Exception $e) {
            return [
                'success' => 'false',
                'message' => 'Error getting status: ' . $e->getMessage(),
                'data' => []
            ];
        }
    }

    public function getAttendanceList($request)
    {
        try {
            $query = self::with(['shift', 'breaks']);

            if ($request->has('user_id')) {
                $query->where('user_id', $request->user_id);
            }

            if ($request->has('date_from')) {
                $query->where('date', '>=', $request->date_from);
            }

            if ($request->has('date_to')) {
                $query->where('date', '<=', $request->date_to);
            }

            if ($request->has('status')) {
                $query->where('status', $request->status);
            }

            $total = $query->count();

            $start = $request->input('start', 0);
            $limit = $request->input('limit', 10);

            $attendances = $query->orderBy('date', 'desc')
                                 ->skip($start)
                                 ->take($limit)
                                 ->get();

            return [
                'success' => 'true',
                'message' => 'Attendance records retrieved.',
                'data' => $attendances,
                'total' => $total
            ];
        } catch (\Exception $e) {
            return [
                'success' => 'false',
                'message' => 'Error retrieving attendance: ' . $e->getMessage(),
                'data' => []
            ];
        }
    }

    public function updateAttendance($request)
    {
        try {
            $attendance = self::find($request->attendance_id);

            if (!$attendance) {
                return [
                    'success' => 'false',
                    'message' => 'Attendance record not found.',
                    'data' => []
                ];
            }

            if ($request->has('clock_in_at')) $attendance->clock_in_at = $request->clock_in_at;
            if ($request->has('clock_out_at')) $attendance->clock_out_at = $request->clock_out_at;
            if ($request->has('status')) $attendance->status = $request->status;
            if ($request->has('notes')) $attendance->notes = $request->notes;

            if ($attendance->clock_in_at && $attendance->clock_out_at) {
                $clockIn = Carbon::parse($attendance->clock_in_at);
                $clockOut = Carbon::parse($attendance->clock_out_at);
                $totalHours = $clockIn->diffInMinutes($clockOut) / 60;
                $breakHours = $attendance->breaks()->sum('duration_minutes') / 60;
                $attendance->total_hours = round($totalHours - $breakHours, 2);
                $attendance->break_hours = round($breakHours, 2);
            }

            $attendance->save();

            return [
                'success' => 'true',
                'message' => 'Attendance updated successfully.',
                'data' => $attendance
            ];
        } catch (\Exception $e) {
            return [
                'success' => 'false',
                'message' => 'Error updating attendance: ' . $e->getMessage(),
                'data' => []
            ];
        }
    }
}
