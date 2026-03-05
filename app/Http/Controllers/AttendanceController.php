<?php

namespace App\Http\Controllers;

use App\Model\Client\Attendance;
use App\Model\Client\AttendanceBreak;
use Illuminate\Http\Request;

class AttendanceController extends Controller
{
    private $request;
    private $model;
    private $breakModel;

    public function __construct(Request $request, Attendance $attendance, AttendanceBreak $attendanceBreak)
    {
        $this->request = $request;
        $this->model = $attendance;
        $this->breakModel = $attendanceBreak;
    }

    /**
     * @OA\Post(
     *     path="/attendance/clock-in",
     *     summary="Clock in for the day",
     *     tags={"Attendance"},
     *     security={{"Bearer":{}}},
     *     @OA\Response(
     *         response=200,
     *         description="Clocked in successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="string", example="true"),
     *             @OA\Property(property="message", type="string", example="Clocked in successfully."),
     *             @OA\Property(property="is_late", type="boolean", example=false),
     *             @OA\Property(property="late_minutes", type="integer", example=0)
     *         )
     *     )
     * )
     */
    public function clockIn()
    {
        $userId = $this->request->auth->id;
        $response = $this->model->clockIn($this->request, $userId);
        return response()->json($response);
    }

    /**
     * @OA\Post(
     *     path="/attendance/clock-out",
     *     summary="Clock out for the day",
     *     tags={"Attendance"},
     *     security={{"Bearer":{}}},
     *     @OA\Response(
     *         response=200,
     *         description="Clocked out successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="string", example="true"),
     *             @OA\Property(property="message", type="string", example="Clocked out successfully."),
     *             @OA\Property(property="total_hours", type="number", example=8.5),
     *             @OA\Property(property="is_early_departure", type="boolean", example=false)
     *         )
     *     )
     * )
     */
    public function clockOut()
    {
        $userId = $this->request->auth->id;
        $response = $this->model->clockOut($this->request, $userId);
        return response()->json($response);
    }

    /**
     * @OA\Get(
     *     path="/attendance/status",
     *     summary="Get current attendance status",
     *     tags={"Attendance"},
     *     security={{"Bearer":{}}},
     *     @OA\Response(
     *         response=200,
     *         description="Attendance status retrieved",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="string", example="true"),
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="is_clocked_in", type="boolean"),
     *                 @OA\Property(property="is_on_break", type="boolean"),
     *                 @OA\Property(property="clock_in_at", type="string", format="datetime")
     *             )
     *         )
     *     )
     * )
     */
    public function getStatus()
    {
        $userId = $this->request->auth->id;
        $response = $this->model->getAttendanceStatus($userId);
        return response()->json($response);
    }

    /**
     * @OA\Post(
     *     path="/attendance/break/start",
     *     summary="Start a break",
     *     tags={"Attendance"},
     *     security={{"Bearer":{}}},
     *     @OA\RequestBody(
     *         @OA\JsonContent(
     *             @OA\Property(property="break_type", type="string", enum={"lunch", "short", "personal", "other"}, example="lunch"),
     *             @OA\Property(property="notes", type="string", example="Lunch break")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Break started"
     *     )
     * )
     */
    public function startBreak()
    {
        $this->validate($this->request, [
            'break_type' => 'in:lunch,short,personal,other',
            'notes' => 'string|max:500'
        ]);
        $userId = $this->request->auth->id;
        $response = $this->breakModel->startBreak($this->request, $userId);
        return response()->json($response);
    }

    /**
     * @OA\Post(
     *     path="/attendance/break/end",
     *     summary="End current break",
     *     tags={"Attendance"},
     *     security={{"Bearer":{}}},
     *     @OA\Response(
     *         response=200,
     *         description="Break ended",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="string", example="true"),
     *             @OA\Property(property="duration_minutes", type="integer", example=30)
     *         )
     *     )
     * )
     */
    public function endBreak()
    {
        $userId = $this->request->auth->id;
        $response = $this->breakModel->endBreak($this->request, $userId);
        return response()->json($response);
    }

    /**
     * @OA\Post(
     *     path="/attendance/list",
     *     summary="Get attendance records",
     *     tags={"Attendance"},
     *     security={{"Bearer":{}}},
     *     @OA\RequestBody(
     *         @OA\JsonContent(
     *             @OA\Property(property="user_id", type="integer", example=1),
     *             @OA\Property(property="date_from", type="string", format="date", example="2026-01-01"),
     *             @OA\Property(property="date_to", type="string", format="date", example="2026-01-31"),
     *             @OA\Property(property="status", type="string", enum={"present", "absent", "late", "early_departure", "half_day", "on_leave"}),
     *             @OA\Property(property="start", type="integer", default=0),
     *             @OA\Property(property="limit", type="integer", default=10)
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Attendance records retrieved"
     *     )
     * )
     */
    public function getAttendanceList()
    {
        $this->validate($this->request, [
            'user_id' => 'numeric',
            'date_from' => 'date',
            'date_to' => 'date',
            'status' => 'in:present,absent,late,early_departure,half_day,on_leave',
            'start' => 'numeric',
            'limit' => 'numeric'
        ]);
        $response = $this->model->getAttendanceList($this->request);
        return response()->json($response);
    }

    /**
     * @OA\Post(
     *     path="/attendance/update",
     *     summary="Update attendance record (admin)",
     *     tags={"Attendance"},
     *     security={{"Bearer":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"attendance_id"},
     *             @OA\Property(property="attendance_id", type="integer", example=1),
     *             @OA\Property(property="clock_in_at", type="string", format="datetime"),
     *             @OA\Property(property="clock_out_at", type="string", format="datetime"),
     *             @OA\Property(property="status", type="string"),
     *             @OA\Property(property="notes", type="string")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Attendance updated"
     *     )
     * )
     */
    public function updateAttendance()
    {
        $this->validate($this->request, [
            'attendance_id' => 'required|numeric',
            'clock_in_at' => 'date',
            'clock_out_at' => 'date',
            'status' => 'in:present,absent,late,early_departure,half_day,on_leave',
            'notes' => 'string|max:1000'
        ]);
        $response = $this->model->updateAttendance($this->request);
        return response()->json($response);
    }

    /**
     * @OA\Get(
     *     path="/attendance/my-attendance",
     *     summary="Get current user's attendance history",
     *     tags={"Attendance"},
     *     security={{"Bearer":{}}},
     *     @OA\Parameter(
     *         name="date_from",
     *         in="query",
     *         @OA\Schema(type="string", format="date")
     *     ),
     *     @OA\Parameter(
     *         name="date_to",
     *         in="query",
     *         @OA\Schema(type="string", format="date")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="User attendance retrieved"
     *     )
     * )
     */
    public function getMyAttendance()
    {
        $userId = $this->request->auth->id;
        $this->request->merge(['user_id' => $userId]);
        $response = $this->model->getAttendanceList($this->request);
        return response()->json($response);
    }
}
