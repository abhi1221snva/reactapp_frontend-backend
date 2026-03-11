<?php

namespace App\Http\Controllers;

use App\Model\Client\Shift;
use Illuminate\Http\Request;

class ShiftController extends Controller
{
    private $request;
    private $model;

    public function __construct(Request $request, Shift $shift)
    {
        $this->request = $request;
        $this->model = $shift;
    }

    /**
     * @OA\Post(
     *     path="/shift/list",
     *     summary="Get list of shifts",
     *     tags={"Shift Management"},
     *     security={{"Bearer":{}}},
     *     @OA\RequestBody(
     *         @OA\JsonContent(
     *             @OA\Property(property="shift_id", type="integer", description="Filter by specific shift ID"),
     *             @OA\Property(property="user_id", type="integer", description="Filter shifts for a specific user"),
     *             @OA\Property(property="start", type="integer", default=0),
     *             @OA\Property(property="limit", type="integer", default=10)
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Shifts retrieved successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="string", example="true"),
     *             @OA\Property(property="message", type="string"),
     *             @OA\Property(property="data", type="array", @OA\Items(type="object")),
     *             @OA\Property(property="total", type="integer")
     *         )
     *     )
     * )
     */
    public function getShiftList()
    {
        $this->validate($this->request, [
            'shift_id' => 'numeric',
            'user_id' => 'numeric',
            'start' => 'numeric',
            'limit' => 'numeric'
        ]);
        $response = $this->model->shiftList($this->request);
        return response()->json($response);
    }

    /**
     * @OA\Post(
     *     path="/shift/add",
     *     summary="Create a new shift",
     *     tags={"Shift Management"},
     *     security={{"Bearer":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"name", "start_time", "end_time"},
     *             @OA\Property(property="name", type="string", example="Morning Shift"),
     *             @OA\Property(property="start_time", type="string", format="time", example="09:00:00"),
     *             @OA\Property(property="end_time", type="string", format="time", example="17:00:00"),
     *             @OA\Property(property="user_id", type="integer", description="Assign to specific user (null for global)"),
     *             @OA\Property(property="grace_period_minutes", type="integer", default=15),
     *             @OA\Property(property="early_departure_minutes", type="integer", default=15),
     *             @OA\Property(property="working_days", type="array", @OA\Items(type="integer"), example={1,2,3,4,5}),
     *             @OA\Property(property="break_duration_minutes", type="integer", default=60),
     *             @OA\Property(property="is_default", type="boolean", default=false)
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Shift created successfully"
     *     )
     * )
     */
    public function addShift()
    {
        $this->validate($this->request, [
            'name' => 'required|string|max:100',
            'start_time' => 'required|date_format:H:i,H:i:s',
            'end_time' => 'required|date_format:H:i,H:i:s',
            'user_id' => 'numeric',
            'grace_period_minutes' => 'numeric|min:0|max:120',
            'early_departure_minutes' => 'numeric|min:0|max:120',
            'working_days' => 'array',
            'break_duration_minutes' => 'numeric|min:0|max:180',
            'is_default' => 'boolean'
        ]);
        $response = $this->model->addShift($this->request);
        return response()->json($response);
    }

    /**
     * @OA\Post(
     *     path="/shift/update",
     *     summary="Update an existing shift",
     *     tags={"Shift Management"},
     *     security={{"Bearer":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"shift_id"},
     *             @OA\Property(property="shift_id", type="integer", example=1),
     *             @OA\Property(property="name", type="string"),
     *             @OA\Property(property="start_time", type="string", format="time"),
     *             @OA\Property(property="end_time", type="string", format="time"),
     *             @OA\Property(property="grace_period_minutes", type="integer"),
     *             @OA\Property(property="early_departure_minutes", type="integer"),
     *             @OA\Property(property="working_days", type="array", @OA\Items(type="integer")),
     *             @OA\Property(property="break_duration_minutes", type="integer"),
     *             @OA\Property(property="is_default", type="boolean"),
     *             @OA\Property(property="is_active", type="boolean")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Shift updated successfully"
     *     )
     * )
     */
    public function updateShift()
    {
        $this->validate($this->request, [
            'shift_id' => 'required|numeric',
            'name' => 'string|max:100',
            'start_time' => 'date_format:H:i,H:i:s',
            'end_time' => 'date_format:H:i,H:i:s',
            'grace_period_minutes' => 'numeric|min:0|max:120',
            'early_departure_minutes' => 'numeric|min:0|max:120',
            'working_days' => 'array',
            'break_duration_minutes' => 'numeric|min:0|max:180',
            'is_default' => 'boolean',
            'is_active' => 'boolean'
        ]);
        $response = $this->model->updateShift($this->request);
        return response()->json($response);
    }

    /**
     * @OA\Post(
     *     path="/shift/delete",
     *     summary="Delete a shift (soft delete)",
     *     tags={"Shift Management"},
     *     security={{"Bearer":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"shift_id"},
     *             @OA\Property(property="shift_id", type="integer", example=1)
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Shift deleted successfully"
     *     )
     * )
     */
    public function deleteShift()
    {
        $this->validate($this->request, [
            'shift_id' => 'required|numeric'
        ]);
        $response = $this->model->deleteShift($this->request);
        return response()->json($response);
    }

    /**
     * @OA\Post(
     *     path="/shift/assign",
     *     summary="Assign a shift to a user",
     *     tags={"Shift Management"},
     *     security={{"Bearer":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"shift_id", "user_id"},
     *             @OA\Property(property="shift_id", type="integer", example=1),
     *             @OA\Property(property="user_id", type="integer", example=10),
     *             @OA\Property(property="is_default", type="boolean", default=true)
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Shift assigned to user"
     *     )
     * )
     */
    public function assignShiftToUser()
    {
        $this->validate($this->request, [
            'shift_id' => 'required|numeric',
            'user_id' => 'required|numeric',
            'is_default' => 'boolean'
        ]);

        try {
            $originalShift = Shift::find($this->request->shift_id);

            if (!$originalShift) {
                return response()->json([
                    'success' => 'false',
                    'message' => 'Shift not found.',
                    'data' => []
                ]);
            }

            $existingUserShift = Shift::where('user_id', $this->request->user_id)
                                      ->where('name', $originalShift->name)
                                      ->first();

            if ($existingUserShift) {
                $existingUserShift->is_default = $this->request->input('is_default', true);
                $existingUserShift->save();

                if ($existingUserShift->is_default) {
                    Shift::where('user_id', $this->request->user_id)
                         ->where('id', '!=', $existingUserShift->id)
                         ->update(['is_default' => false]);
                }

                return response()->json([
                    'success' => 'true',
                    'message' => 'User shift updated.',
                    'data' => $existingUserShift
                ]);
            }

            $userShift = $originalShift->replicate();
            $userShift->user_id = $this->request->user_id;
            $userShift->is_default = $this->request->input('is_default', true);
            $userShift->save();

            if ($userShift->is_default) {
                Shift::where('user_id', $this->request->user_id)
                     ->where('id', '!=', $userShift->id)
                     ->update(['is_default' => false]);
            }

            return response()->json([
                'success' => 'true',
                'message' => 'Shift assigned to user successfully.',
                'data' => $userShift
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => 'false',
                'message' => 'Error assigning shift: ' . $e->getMessage(),
                'data' => []
            ]);
        }
    }
}
