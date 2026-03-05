<?php

namespace App\Model\Client;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class Shift extends Model
{
    protected $table = 'shifts';

    protected $fillable = [
        'user_id',
        'name',
        'start_time',
        'end_time',
        'grace_period_minutes',
        'early_departure_minutes',
        'working_days',
        'break_duration_minutes',
        'is_default',
        'is_active'
    ];

    protected $casts = [
        'working_days' => 'array',
        'is_default' => 'boolean',
        'is_active' => 'boolean'
    ];

    public function attendances()
    {
        return $this->hasMany(Attendance::class, 'shift_id');
    }

    public function shiftList($request)
    {
        try {
            $query = self::where('is_active', true);

            if ($request->has('shift_id')) {
                $query->where('id', $request->shift_id);
            }

            if ($request->has('user_id')) {
                $query->where(function($q) use ($request) {
                    $q->where('user_id', $request->user_id)
                      ->orWhereNull('user_id');
                });
            }

            $total = $query->count();

            $start = $request->input('start', 0);
            $limit = $request->input('limit', 10);

            $shifts = $query->orderBy('name', 'asc')
                           ->skip($start)
                           ->take($limit)
                           ->get();

            return [
                'success' => 'true',
                'message' => 'Shifts retrieved successfully.',
                'data' => $shifts,
                'total' => $total
            ];
        } catch (\Exception $e) {
            return [
                'success' => 'false',
                'message' => 'Error retrieving shifts: ' . $e->getMessage(),
                'data' => []
            ];
        }
    }

    public function addShift($request)
    {
        try {
            $shift = new self();
            $shift->user_id = $request->input('user_id');
            $shift->name = $request->name;
            $shift->start_time = $request->start_time;
            $shift->end_time = $request->end_time;
            $shift->grace_period_minutes = $request->input('grace_period_minutes', 15);
            $shift->early_departure_minutes = $request->input('early_departure_minutes', 15);
            $shift->working_days = $request->input('working_days', [1, 2, 3, 4, 5]);
            $shift->break_duration_minutes = $request->input('break_duration_minutes', 60);
            $shift->is_default = $request->input('is_default', false);
            $shift->save();

            if ($shift->is_default) {
                self::where('id', '!=', $shift->id)
                    ->where('user_id', $shift->user_id)
                    ->update(['is_default' => false]);
            }

            return [
                'success' => 'true',
                'message' => 'Shift created successfully.',
                'data' => $shift
            ];
        } catch (\Exception $e) {
            return [
                'success' => 'false',
                'message' => 'Error creating shift: ' . $e->getMessage(),
                'data' => []
            ];
        }
    }

    public function updateShift($request)
    {
        try {
            $shift = self::find($request->shift_id);

            if (!$shift) {
                return [
                    'success' => 'false',
                    'message' => 'Shift not found.',
                    'data' => []
                ];
            }

            if ($request->has('name')) $shift->name = $request->name;
            if ($request->has('start_time')) $shift->start_time = $request->start_time;
            if ($request->has('end_time')) $shift->end_time = $request->end_time;
            if ($request->has('grace_period_minutes')) $shift->grace_period_minutes = $request->grace_period_minutes;
            if ($request->has('early_departure_minutes')) $shift->early_departure_minutes = $request->early_departure_minutes;
            if ($request->has('working_days')) $shift->working_days = $request->working_days;
            if ($request->has('break_duration_minutes')) $shift->break_duration_minutes = $request->break_duration_minutes;
            if ($request->has('is_default')) {
                $shift->is_default = $request->is_default;
                if ($request->is_default) {
                    self::where('id', '!=', $shift->id)
                        ->where('user_id', $shift->user_id)
                        ->update(['is_default' => false]);
                }
            }
            if ($request->has('is_active')) $shift->is_active = $request->is_active;

            $shift->save();

            return [
                'success' => 'true',
                'message' => 'Shift updated successfully.',
                'data' => $shift
            ];
        } catch (\Exception $e) {
            return [
                'success' => 'false',
                'message' => 'Error updating shift: ' . $e->getMessage(),
                'data' => []
            ];
        }
    }

    public function deleteShift($request)
    {
        try {
            $shift = self::find($request->shift_id);

            if (!$shift) {
                return [
                    'success' => 'false',
                    'message' => 'Shift not found.',
                    'data' => []
                ];
            }

            $shift->is_active = false;
            $shift->save();

            return [
                'success' => 'true',
                'message' => 'Shift deleted successfully.',
                'data' => []
            ];
        } catch (\Exception $e) {
            return [
                'success' => 'false',
                'message' => 'Error deleting shift: ' . $e->getMessage(),
                'data' => []
            ];
        }
    }

    public function getUserShift($userId)
    {
        $shift = self::where('user_id', $userId)
                     ->where('is_active', true)
                     ->where('is_default', true)
                     ->first();

        if (!$shift) {
            $shift = self::whereNull('user_id')
                         ->where('is_active', true)
                         ->where('is_default', true)
                         ->first();
        }

        return $shift;
    }
}
