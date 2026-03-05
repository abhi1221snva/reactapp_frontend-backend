<?php

namespace App\Http\Controllers;

use App\Model\Client\Attendance;
use App\Model\Client\Shift;
use App\Model\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class AttendanceReportController extends Controller
{
    private $request;

    public function __construct(Request $request)
    {
        $this->request = $request;
    }

    /**
     * @OA\Post(
     *     path="/attendance/report/daily",
     *     summary="Get daily attendance report",
     *     tags={"Attendance Reports"},
     *     security={{"Bearer":{}}},
     *     @OA\RequestBody(
     *         @OA\JsonContent(
     *             @OA\Property(property="date", type="string", format="date", example="2026-01-04"),
     *             @OA\Property(property="user_id", type="integer", description="Filter by specific user")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Daily report retrieved"
     *     )
     * )
     */
    public function getDailyReport()
    {
        $this->validate($this->request, [
            'date' => 'date',
            'user_id' => 'numeric'
        ]);

        try {
            $date = $this->request->input('date', Carbon::today()->toDateString());
            $parentId = $this->request->auth->parent_id ?: $this->request->auth->id;

            $query = Attendance::where('date', $date)
                              ->with(['shift', 'breaks']);

            if ($this->request->has('user_id')) {
                $query->where('user_id', $this->request->user_id);
            }

            $attendances = $query->get();

            $users = User::where('parent_id', $parentId)
                         ->orWhere('id', $parentId)
                         ->select('id', 'first_name', 'last_name', 'email', 'extension')
                         ->get()
                         ->keyBy('id');

            $report = [];
            foreach ($attendances as $attendance) {
                $user = $users->get($attendance->user_id);
                $report[] = [
                    'user_id' => $attendance->user_id,
                    'user_name' => $user ? $user->first_name . ' ' . $user->last_name : 'Unknown',
                    'email' => $user ? $user->email : null,
                    'extension' => $user ? $user->extension : null,
                    'date' => $attendance->date,
                    'clock_in' => $attendance->clock_in_at,
                    'clock_out' => $attendance->clock_out_at,
                    'total_hours' => $attendance->total_hours,
                    'break_hours' => $attendance->break_hours,
                    'overtime_hours' => $attendance->overtime_hours,
                    'status' => $attendance->status,
                    'is_late' => $attendance->is_late,
                    'late_minutes' => $attendance->late_minutes,
                    'is_early_departure' => $attendance->is_early_departure,
                    'early_departure_minutes' => $attendance->early_departure_minutes,
                    'breaks' => $attendance->breaks
                ];
            }

            $presentCount = $attendances->where('status', 'present')->count();
            $lateCount = $attendances->where('is_late', true)->count();
            $earlyDepartureCount = $attendances->where('is_early_departure', true)->count();
            $absentCount = $users->count() - $attendances->count();

            return response()->json([
                'success' => 'true',
                'message' => 'Daily attendance report retrieved.',
                'data' => [
                    'date' => $date,
                    'summary' => [
                        'total_employees' => $users->count(),
                        'present' => $presentCount,
                        'late' => $lateCount,
                        'early_departure' => $earlyDepartureCount,
                        'absent' => $absentCount,
                        'total_hours_worked' => $attendances->sum('total_hours'),
                        'total_overtime' => $attendances->sum('overtime_hours')
                    ],
                    'records' => $report
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => 'false',
                'message' => 'Error generating daily report: ' . $e->getMessage(),
                'data' => []
            ]);
        }
    }

    /**
     * @OA\Post(
     *     path="/attendance/report/weekly",
     *     summary="Get weekly attendance report",
     *     tags={"Attendance Reports"},
     *     security={{"Bearer":{}}},
     *     @OA\RequestBody(
     *         @OA\JsonContent(
     *             @OA\Property(property="week_start", type="string", format="date", example="2025-12-30"),
     *             @OA\Property(property="user_id", type="integer")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Weekly report retrieved"
     *     )
     * )
     */
    public function getWeeklyReport()
    {
        $this->validate($this->request, [
            'week_start' => 'date',
            'user_id' => 'numeric'
        ]);

        try {
            $weekStart = $this->request->has('week_start')
                ? Carbon::parse($this->request->week_start)->startOfWeek()
                : Carbon::now()->startOfWeek();
            $weekEnd = $weekStart->copy()->endOfWeek();
            $parentId = $this->request->auth->parent_id ?: $this->request->auth->id;

            $query = Attendance::whereBetween('date', [$weekStart->toDateString(), $weekEnd->toDateString()])
                              ->with(['shift']);

            if ($this->request->has('user_id')) {
                $query->where('user_id', $this->request->user_id);
            }

            $attendances = $query->get();

            $users = User::where('parent_id', $parentId)
                         ->orWhere('id', $parentId)
                         ->select('id', 'first_name', 'last_name', 'email')
                         ->get();

            $userReports = [];
            foreach ($users as $user) {
                $userAttendances = $attendances->where('user_id', $user->id);

                $dailyBreakdown = [];
                $currentDate = $weekStart->copy();
                while ($currentDate <= $weekEnd) {
                    $dayAttendance = $userAttendances->firstWhere('date', $currentDate->toDateString());
                    $dailyBreakdown[$currentDate->format('l')] = [
                        'date' => $currentDate->toDateString(),
                        'status' => $dayAttendance ? $dayAttendance->status : 'absent',
                        'hours' => $dayAttendance ? $dayAttendance->total_hours : 0,
                        'is_late' => $dayAttendance ? $dayAttendance->is_late : false
                    ];
                    $currentDate->addDay();
                }

                $userReports[] = [
                    'user_id' => $user->id,
                    'user_name' => $user->first_name . ' ' . $user->last_name,
                    'email' => $user->email,
                    'total_days_present' => $userAttendances->count(),
                    'total_hours' => $userAttendances->sum('total_hours'),
                    'total_overtime' => $userAttendances->sum('overtime_hours'),
                    'late_count' => $userAttendances->where('is_late', true)->count(),
                    'early_departure_count' => $userAttendances->where('is_early_departure', true)->count(),
                    'average_hours_per_day' => $userAttendances->count() > 0
                        ? round($userAttendances->sum('total_hours') / $userAttendances->count(), 2)
                        : 0,
                    'daily_breakdown' => $dailyBreakdown
                ];
            }

            return response()->json([
                'success' => 'true',
                'message' => 'Weekly attendance report retrieved.',
                'data' => [
                    'week_start' => $weekStart->toDateString(),
                    'week_end' => $weekEnd->toDateString(),
                    'summary' => [
                        'total_employees' => $users->count(),
                        'total_attendance_records' => $attendances->count(),
                        'total_hours_worked' => $attendances->sum('total_hours'),
                        'total_overtime' => $attendances->sum('overtime_hours'),
                        'average_attendance_rate' => $users->count() > 0
                            ? round(($attendances->count() / ($users->count() * 7)) * 100, 1)
                            : 0
                    ],
                    'user_reports' => $userReports
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => 'false',
                'message' => 'Error generating weekly report: ' . $e->getMessage(),
                'data' => []
            ]);
        }
    }

    /**
     * @OA\Post(
     *     path="/attendance/report/monthly",
     *     summary="Get monthly attendance report",
     *     tags={"Attendance Reports"},
     *     security={{"Bearer":{}}},
     *     @OA\RequestBody(
     *         @OA\JsonContent(
     *             @OA\Property(property="month", type="integer", example=1),
     *             @OA\Property(property="year", type="integer", example=2026),
     *             @OA\Property(property="user_id", type="integer")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Monthly report retrieved"
     *     )
     * )
     */
    public function getMonthlyReport()
    {
        $this->validate($this->request, [
            'month' => 'numeric|min:1|max:12',
            'year' => 'numeric|min:2020|max:2050',
            'user_id' => 'numeric'
        ]);

        try {
            $month = $this->request->input('month', Carbon::now()->month);
            $year = $this->request->input('year', Carbon::now()->year);
            $parentId = $this->request->auth->parent_id ?: $this->request->auth->id;

            $monthStart = Carbon::createFromDate($year, $month, 1)->startOfMonth();
            $monthEnd = $monthStart->copy()->endOfMonth();
            $workingDays = $this->getWorkingDays($monthStart, $monthEnd);

            $query = Attendance::whereBetween('date', [$monthStart->toDateString(), $monthEnd->toDateString()])
                              ->with(['shift']);

            if ($this->request->has('user_id')) {
                $query->where('user_id', $this->request->user_id);
            }

            $attendances = $query->get();

            $users = User::where('parent_id', $parentId)
                         ->orWhere('id', $parentId)
                         ->select('id', 'first_name', 'last_name', 'email')
                         ->get();

            $userReports = [];
            foreach ($users as $user) {
                $userAttendances = $attendances->where('user_id', $user->id);

                $presentDays = $userAttendances->count();
                $lateDays = $userAttendances->where('is_late', true)->count();
                $totalLateMinutes = $userAttendances->sum('late_minutes');
                $earlyDepartures = $userAttendances->where('is_early_departure', true)->count();
                $totalHours = $userAttendances->sum('total_hours');
                $overtimeHours = $userAttendances->sum('overtime_hours');

                $userReports[] = [
                    'user_id' => $user->id,
                    'user_name' => $user->first_name . ' ' . $user->last_name,
                    'email' => $user->email,
                    'working_days' => $workingDays,
                    'present_days' => $presentDays,
                    'absent_days' => $workingDays - $presentDays,
                    'attendance_percentage' => $workingDays > 0
                        ? round(($presentDays / $workingDays) * 100, 1)
                        : 0,
                    'late_days' => $lateDays,
                    'total_late_minutes' => $totalLateMinutes,
                    'average_late_minutes' => $lateDays > 0 ? round($totalLateMinutes / $lateDays, 1) : 0,
                    'early_departure_days' => $earlyDepartures,
                    'total_hours_worked' => round($totalHours, 2),
                    'overtime_hours' => round($overtimeHours, 2),
                    'average_hours_per_day' => $presentDays > 0
                        ? round($totalHours / $presentDays, 2)
                        : 0
                ];
            }

            return response()->json([
                'success' => 'true',
                'message' => 'Monthly attendance report retrieved.',
                'data' => [
                    'month' => $month,
                    'year' => $year,
                    'month_name' => $monthStart->format('F'),
                    'month_start' => $monthStart->toDateString(),
                    'month_end' => $monthEnd->toDateString(),
                    'working_days' => $workingDays,
                    'summary' => [
                        'total_employees' => $users->count(),
                        'total_attendance_records' => $attendances->count(),
                        'total_hours_worked' => round($attendances->sum('total_hours'), 2),
                        'total_overtime' => round($attendances->sum('overtime_hours'), 2),
                        'total_late_instances' => $attendances->where('is_late', true)->count(),
                        'overall_attendance_rate' => ($users->count() * $workingDays) > 0
                            ? round(($attendances->count() / ($users->count() * $workingDays)) * 100, 1)
                            : 0
                    ],
                    'user_reports' => $userReports
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => 'false',
                'message' => 'Error generating monthly report: ' . $e->getMessage(),
                'data' => []
            ]);
        }
    }

    /**
     * @OA\Post(
     *     path="/attendance/report/summary",
     *     summary="Get attendance summary for a date range",
     *     tags={"Attendance Reports"},
     *     security={{"Bearer":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"date_from", "date_to"},
     *             @OA\Property(property="date_from", type="string", format="date"),
     *             @OA\Property(property="date_to", type="string", format="date"),
     *             @OA\Property(property="user_id", type="integer")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Summary report retrieved"
     *     )
     * )
     */
    public function getSummaryReport()
    {
        $this->validate($this->request, [
            'date_from' => 'required|date',
            'date_to' => 'required|date|after_or_equal:date_from',
            'user_id' => 'numeric'
        ]);

        try {
            $dateFrom = Carbon::parse($this->request->date_from);
            $dateTo = Carbon::parse($this->request->date_to);
            $parentId = $this->request->auth->parent_id ?: $this->request->auth->id;

            $query = Attendance::whereBetween('date', [$dateFrom->toDateString(), $dateTo->toDateString()]);

            if ($this->request->has('user_id')) {
                $query->where('user_id', $this->request->user_id);
            }

            $attendances = $query->get();

            $statusBreakdown = [
                'present' => $attendances->where('status', 'present')->count(),
                'late' => $attendances->where('status', 'late')->count(),
                'early_departure' => $attendances->where('status', 'early_departure')->count(),
                'half_day' => $attendances->where('status', 'half_day')->count(),
                'on_leave' => $attendances->where('status', 'on_leave')->count()
            ];

            $hoursBreakdown = [
                'total_regular_hours' => round($attendances->sum('total_hours') - $attendances->sum('overtime_hours'), 2),
                'total_overtime_hours' => round($attendances->sum('overtime_hours'), 2),
                'total_break_hours' => round($attendances->sum('break_hours'), 2),
                'average_daily_hours' => $attendances->count() > 0
                    ? round($attendances->sum('total_hours') / $attendances->count(), 2)
                    : 0
            ];

            $punctualityStats = [
                'on_time_arrivals' => $attendances->where('is_late', false)->count(),
                'late_arrivals' => $attendances->where('is_late', true)->count(),
                'total_late_minutes' => $attendances->sum('late_minutes'),
                'average_late_minutes' => $attendances->where('is_late', true)->count() > 0
                    ? round($attendances->sum('late_minutes') / $attendances->where('is_late', true)->count(), 1)
                    : 0,
                'early_departures' => $attendances->where('is_early_departure', true)->count(),
                'total_early_departure_minutes' => $attendances->sum('early_departure_minutes')
            ];

            return response()->json([
                'success' => 'true',
                'message' => 'Summary report retrieved.',
                'data' => [
                    'date_from' => $dateFrom->toDateString(),
                    'date_to' => $dateTo->toDateString(),
                    'days_in_range' => $dateFrom->diffInDays($dateTo) + 1,
                    'total_attendance_records' => $attendances->count(),
                    'status_breakdown' => $statusBreakdown,
                    'hours_breakdown' => $hoursBreakdown,
                    'punctuality_stats' => $punctualityStats
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => 'false',
                'message' => 'Error generating summary report: ' . $e->getMessage(),
                'data' => []
            ]);
        }
    }

    /**
     * @OA\Post(
     *     path="/attendance/report/alerts",
     *     summary="Get late arrival and early departure alerts",
     *     tags={"Attendance Reports"},
     *     security={{"Bearer":{}}},
     *     @OA\RequestBody(
     *         @OA\JsonContent(
     *             @OA\Property(property="date_from", type="string", format="date"),
     *             @OA\Property(property="date_to", type="string", format="date"),
     *             @OA\Property(property="alert_type", type="string", enum={"late", "early_departure", "all"}, example="all"),
     *             @OA\Property(property="min_late_minutes", type="integer", default=0),
     *             @OA\Property(property="start", type="integer", default=0),
     *             @OA\Property(property="limit", type="integer", default=50)
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Alerts retrieved"
     *     )
     * )
     */
    public function getLateEarlyAlerts()
    {
        $this->validate($this->request, [
            'date_from' => 'date',
            'date_to' => 'date',
            'alert_type' => 'in:late,early_departure,all',
            'min_late_minutes' => 'numeric|min:0',
            'start' => 'numeric',
            'limit' => 'numeric'
        ]);

        try {
            $dateFrom = $this->request->input('date_from', Carbon::now()->subDays(7)->toDateString());
            $dateTo = $this->request->input('date_to', Carbon::today()->toDateString());
            $alertType = $this->request->input('alert_type', 'all');
            $minLateMinutes = $this->request->input('min_late_minutes', 0);
            $parentId = $this->request->auth->parent_id ?: $this->request->auth->id;

            $query = Attendance::whereBetween('date', [$dateFrom, $dateTo]);

            if ($alertType === 'late') {
                $query->where('is_late', true);
                if ($minLateMinutes > 0) {
                    $query->where('late_minutes', '>=', $minLateMinutes);
                }
            } elseif ($alertType === 'early_departure') {
                $query->where('is_early_departure', true);
            } else {
                $query->where(function($q) use ($minLateMinutes) {
                    $q->where('is_late', true)
                      ->orWhere('is_early_departure', true);
                });
                if ($minLateMinutes > 0) {
                    $query->where(function($q) use ($minLateMinutes) {
                        $q->where('late_minutes', '>=', $minLateMinutes)
                          ->orWhere('is_early_departure', true);
                    });
                }
            }

            $total = $query->count();

            $start = $this->request->input('start', 0);
            $limit = $this->request->input('limit', 50);

            $alerts = $query->orderBy('date', 'desc')
                           ->orderBy('late_minutes', 'desc')
                           ->skip($start)
                           ->take($limit)
                           ->get();

            $users = User::where('parent_id', $parentId)
                         ->orWhere('id', $parentId)
                         ->select('id', 'first_name', 'last_name', 'email', 'extension')
                         ->get()
                         ->keyBy('id');

            $alertsList = [];
            foreach ($alerts as $alert) {
                $user = $users->get($alert->user_id);
                $alertItem = [
                    'attendance_id' => $alert->id,
                    'user_id' => $alert->user_id,
                    'user_name' => $user ? $user->first_name . ' ' . $user->last_name : 'Unknown',
                    'email' => $user ? $user->email : null,
                    'extension' => $user ? $user->extension : null,
                    'date' => $alert->date,
                    'alert_types' => []
                ];

                if ($alert->is_late) {
                    $alertItem['alert_types'][] = [
                        'type' => 'late',
                        'clock_in_at' => $alert->clock_in_at,
                        'late_minutes' => $alert->late_minutes,
                        'severity' => $this->getLateSeverity($alert->late_minutes)
                    ];
                }

                if ($alert->is_early_departure) {
                    $alertItem['alert_types'][] = [
                        'type' => 'early_departure',
                        'clock_out_at' => $alert->clock_out_at,
                        'early_minutes' => $alert->early_departure_minutes,
                        'severity' => $this->getEarlyDepartureSeverity($alert->early_departure_minutes)
                    ];
                }

                $alertsList[] = $alertItem;
            }

            $severitySummary = [
                'critical' => 0,
                'high' => 0,
                'medium' => 0,
                'low' => 0
            ];

            foreach ($alertsList as $alert) {
                foreach ($alert['alert_types'] as $alertType) {
                    $severity = $alertType['severity'];
                    if (isset($severitySummary[$severity])) {
                        $severitySummary[$severity]++;
                    }
                }
            }

            return response()->json([
                'success' => 'true',
                'message' => 'Alerts retrieved successfully.',
                'data' => [
                    'date_from' => $dateFrom,
                    'date_to' => $dateTo,
                    'severity_summary' => $severitySummary,
                    'alerts' => $alertsList
                ],
                'total' => $total
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => 'false',
                'message' => 'Error retrieving alerts: ' . $e->getMessage(),
                'data' => []
            ]);
        }
    }

    private function getWorkingDays($start, $end)
    {
        $workingDays = 0;
        $current = $start->copy();

        while ($current <= $end) {
            if ($current->isWeekday()) {
                $workingDays++;
            }
            $current->addDay();
        }

        return $workingDays;
    }

    private function getLateSeverity($minutes)
    {
        if ($minutes >= 60) return 'critical';
        if ($minutes >= 30) return 'high';
        if ($minutes >= 15) return 'medium';
        return 'low';
    }

    private function getEarlyDepartureSeverity($minutes)
    {
        if ($minutes >= 120) return 'critical';
        if ($minutes >= 60) return 'high';
        if ($minutes >= 30) return 'medium';
        return 'low';
    }
}
