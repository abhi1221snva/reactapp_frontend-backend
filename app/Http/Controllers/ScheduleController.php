<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Model\Client\Schedule;
use App\Model\User;

use Illuminate\Support\Facades\Log;
use Session;
use DateTime;

class ScheduleController extends Controller
{

    /**
     * @OA\Get(
     *     path="/schedule",
     *     summary="Get list of schedules for a client",
     *     tags={"Schedule"},
     *     security={{"Bearer": {}}},
     *  *      @OA\Parameter(
     *         name="start",
     *         in="query",
     *         required=false,
     *         description="Start index for pagination",
     *         @OA\Schema(type="integer", default=0)
     *     ),
     *     @OA\Parameter(
     *         name="limit",
     *         in="query",
     *         required=false,
     *         description="Limit number of records returned",
     *         @OA\Schema(type="integer", default=10)
     *     ),
     *     @OA\Parameter(
     *         name="client_id",
     *         in="query",
     *         required=true,
     *         description="Client ID to fetch schedules for",
     *         @OA\Schema(type="string")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="List of schedules",
     *         @OA\JsonContent(
     *             type="array",
     *             @OA\Items(
     *                 @OA\Property(property="id", type="integer", example=1),
     *                 @OA\Property(property="title", type="string", example="Conference A"),
     *                 @OA\Property(property="description", type="string", example="Conference A description"),
     *                 @OA\Property(property="start", type="string", format="date-time", example="2025-04-20T10:00:00"),
     *                 @OA\Property(property="end", type="string", format="date-time", example="2025-04-20T12:00:00"),
     *                 @OA\Property(property="timezone", type="string", example="UTC")
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Client ID not provided or invalid"
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Internal server error"
     *     )
     * )
     */

    // public function index(Request $request)
    // {
    //     $client_id = $request->auth->parent_id;
    //     // Log::info('reached',['client_id'=>$client_id]);
    //     $schedules = Schedule::on("mysql_$client_id")->get();
    //     $sched_res = [];

    //     foreach ($schedules as $schedule) {
    //         $event['id'] = $schedule->id;
    //         $event['title'] = $schedule->title;
    //         $event['description'] = $schedule->description;
    //         $event['start'] = date("Y-m-d\TH:i:s", strtotime($schedule->start_datetime));
    //         $event['end'] = date("Y-m-d\TH:i:s", strtotime($schedule->end_datetime));
    //         $event['timezone'] = $schedule->timezone;

    //         $sched_res[] = $event;
    //     }
    //     if ($request->has('start') && $request->has('limit')) {
    //         $total_row = count($sched_res);

    //         $start = (int) $request->input('start');  // Start index (0-based)
    //         $limit = (int) $request->input('limit');  // Number of records to fetch

    //         $sched_res = array_slice($sched_res, $start, $limit, false);

    //         return $this->successResponse("schedules", [
    //             'start' => $start,
    //             'limit' => $limit,
    //             'total' => $total_row,
    //             'data' => $sched_res
    //         ]);
    //     }
    //     return response()->json($sched_res);
    // }
    public function index(Request $request)
{
    $client_id = $request->auth->parent_id;
    $start_date = $request->get('start_date'); // Optional filter start date
    $end_date = $request->get('end_date');     // Optional filter end date

    // Base query
    $query = Schedule::on("mysql_$client_id");

    // Apply date filters if provided
    if (!empty($start_date) && !empty($end_date)) {
        $query->whereBetween('start_datetime', [$start_date, $end_date]);
    } elseif (!empty($start_date)) {
        $query->where('start_datetime', '>=', $start_date);
    } elseif (!empty($end_date)) {
        $query->where('end_datetime', '<=', $end_date);
    }

    $schedules = $query->get();
    $sched_res = [];

    foreach ($schedules as $schedule) {
        $sched_res[] = [
            'id' => $schedule->id,
            'title' => $schedule->title,
            'description' => $schedule->description,
            'start' => date("Y-m-d\TH:i:s", strtotime($schedule->start_datetime)),
            'end' => date("Y-m-d\TH:i:s", strtotime($schedule->end_datetime)),
            'timezone' => $schedule->timezone,
        ];
    }

    // Pagination logic (start & limit)
    if ($request->has('start') && $request->has('limit')) {
        $total_row = count($sched_res);

        $start = (int) $request->input('start');  // Start index (0-based)
        $limit = (int) $request->input('limit');  // Number of records to fetch

        $paged_data = array_slice($sched_res, $start, $limit, false);

        return $this->successResponse("schedules", [
            'start' => $start,
            'limit' => $limit,
            'total' => $total_row,
            'data' => $paged_data
        ]);
    }

    return response()->json($sched_res);
}


    public function index_old_code(Request $request)
    {
        $client_id = $request->get('client_id');
        // Log::info('reached',['client_id'=>$client_id]);
        $schedules = Schedule::on("mysql_$client_id")->get();
        $sched_res = [];

        foreach ($schedules as $schedule) {
            $event['id'] = $schedule->id;
            $event['title'] = $schedule->title;
            $event['description'] = $schedule->description;
            $event['start'] = date("Y-m-d\TH:i:s", strtotime($schedule->start_datetime));
            $event['end'] = date("Y-m-d\TH:i:s", strtotime($schedule->end_datetime));
            $event['timezone'] = $schedule->timezone;

            $sched_res[] = $event;
        }
        return response()->json($sched_res);
    }

    /**
     * @OA\Post(
     *     path="/save-schedule",
     *     summary="Store a new event or update an existing event",
     *     tags={"Schedule"},
     *     security={{"Bearer": {}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             type="object",
     *             required={"title", "start_datetime", "end_datetime", "user_id"},
     *             @OA\Property(property="client_id", type="integer", example=1, description="ID of the event for updating (optional for new events)"),
     *             @OA\Property(property="title", type="string", example="Team Meeting", description="Event title"),
     *             @OA\Property(property="description", type="string", example="Discussion about project progress", description="Event description"),
     *             @OA\Property(property="start_datetime", type="string", format="date-time", example="2025-04-25T10:00:00", description="Event start datetime"),
     *             @OA\Property(property="end_datetime", type="string", format="date-time", example="2025-04-25T12:00:00", description="Event end datetime"),
     *             @OA\Property(property="user_id", type="integer", example=1, description="User ID associated with the event"),
     *             @OA\Property(property="allday", type="boolean", example=false, description="Flag to indicate if the event is all day (optional)")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Event successfully added or updated",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="message", type="string", example="Event Added"),
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="client_id", type="integer", example=1),
     *                 @OA\Property(property="title", type="string", example="Team Meeting"),
     *                 @OA\Property(property="description", type="string", example="Discussion about project progress"),
     *                 @OA\Property(property="start_datetime", type="string", format="date-time", example="2025-04-25T10:00:00"),
     *                 @OA\Property(property="end_datetime", type="string", format="date-time", example="2025-04-25T12:00:00"),
     *                 @OA\Property(property="user_id", type="integer", example=1),
     *                 @OA\Property(property="timezone", type="string", example="UTC")
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Bad request, such as missing required fields or conflicting events",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="message", type="string", example="Another event is already scheduled with the same end time")
     *         )
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Internal server error",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="message", type="string", example="Failed to add event")
     *         )
     *     )
     * )
     */

    public function store(Request $request)
    {
        $client_id = $request->auth->parent_id;
        $user = User::where('id', $request->get('user_id'))->first();
        $timezone = $user->timezone;
        //Log::info('reached',['user'=>$user]);
        try {
            $requestData = $request->all();

            $allday = isset($requestData['allday']);
            // Check if end datetime is less than start datetime
            $startDatetime = new DateTime($requestData['start_datetime']);
            $endDatetime = new DateTime($requestData['end_datetime']);


            // Check if this is a new event or an edit
            if (empty($requestData['id'])) {
                // For a new event, check if there is already an event scheduled with the same end date
                $existingEvent = Schedule::on("mysql_$client_id")
                    ->where('end_datetime', $requestData['end_datetime'])
                    ->first();

                if ($existingEvent) {
                    return $this->failResponse("Another event is already scheduled with the same end time", [], null, 400);
                }
            } else {
                // For an edit, exclude the current event being edited from the check
                $existingEvent = Schedule::on("mysql_$client_id")
                    ->where('id', '<>', $requestData['id']) // Exclude the current event being edited
                    ->where('end_datetime', $requestData['end_datetime']) // Same end date
                    ->first();

                if ($existingEvent) {
                    return $this->failResponse("Another event with the same end time is already scheduled", [], null, 400);
                }
            }


            // Save or update the event
            if (empty($requestData['id'])) {
                $schedule = new Schedule();
                $schedule->setConnection("mysql_$client_id");
            } else {
                $schedule = Schedule::on("mysql_$client_id")->findOrFail($requestData['id']);
            }

            $schedule->title = $requestData['title'];
            $schedule->description = $requestData['description'];
            $schedule->start_datetime = $requestData['start_datetime'];
            $schedule->end_datetime = $requestData['end_datetime'];
            $schedule->user_id = $requestData['user_id'];
            $schedule->timezone = $timezone;

            $schedule->save();

            $scheduleArray = $schedule->toArray();

            return $this->successResponse("Event Added", $scheduleArray);
        } catch (\Throwable $exception) {
            return $this->failResponse("Failed to add event", [$exception->getMessage()], $exception, $exception->getCode());
        }
    }


    /**
     * @OA\Post(
     *     path="/schedule/delete-schedule",
     *     summary="Delete an existing event",
     *     tags={"Schedule"},
     *     security={{"Bearer": {}}},
     *     @OA\Parameter(
     *         name="event_id",
     *         in="path",
     *         required=true,
     *         description="ID of the event to be deleted",
     *         @OA\Schema(type="integer", example=1)
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Event deleted successfully",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="message", type="string", example="Event deleted successfully"),
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="id", type="integer", example=1),
     *                 @OA\Property(property="title", type="string", example="Team Meeting"),
     *                 @OA\Property(property="description", type="string", example="Discussion about project progress"),
     *                 @OA\Property(property="start_datetime", type="string", format="date-time", example="2025-04-25T10:00:00"),
     *                 @OA\Property(property="end_datetime", type="string", format="date-time", example="2025-04-25T12:00:00"),
     *                 @OA\Property(property="user_id", type="integer", example=1),
     *                 @OA\Property(property="timezone", type="string", example="UTC")
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Bad request, event ID not found or failure to delete",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="message", type="string", example="Failed to delete the Event")
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Event not found",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="message", type="string", example="Event not found")
     *         )
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Internal server error",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="message", type="string", example="Failed to delete the Event")
     *         )
     *     )
     * )
     */


    public function deleteSchedule(Request $request)
    {
        $client_id = $request->auth->parent_id;
        $id = $request->event_id;
        $events = Schedule::on("mysql_$client_id")->findOrFail($id);
        $events->delete();
        if ($events) {
            return $this->successResponse("Event deleted successfully", $events->toArray());
        } else {
            return $this->failResponse("Failed to delete the Event ", [
                "Unkown"
            ]);
        }
    }
}
