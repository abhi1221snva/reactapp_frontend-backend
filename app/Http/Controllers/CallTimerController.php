<?php

namespace App\Http\Controllers;

use App\Model\CallTimer;
use Illuminate\Http\Request;
use App\Model\Client\Campaign;
use Carbon\Carbon;

/**
 * @OA\Get(
 *   path="/call-timers",
 *   summary="List call timers",
 *   operationId="callTimerIndex",
 *   tags={"Call Timers"},
 *   security={{"Bearer":{}}},
 *   @OA\Parameter(name="search", in="query", @OA\Schema(type="string")),
 *   @OA\Parameter(name="start", in="query", @OA\Schema(type="integer")),
 *   @OA\Parameter(name="limit", in="query", @OA\Schema(type="integer")),
 *   @OA\Response(response=200, description="List of call timers"),
 *   @OA\Response(response=401, description="Unauthenticated")
 * )
 *
 * @OA\Get(
 *   path="/call-timers/{id}",
 *   summary="Get a single call timer",
 *   operationId="callTimerShow",
 *   tags={"Call Timers"},
 *   security={{"Bearer":{}}},
 *   @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
 *   @OA\Response(response=200, description="Call timer detail"),
 *   @OA\Response(response=404, description="Not found")
 * )
 *
 * @OA\Post(
 *   path="/call-timers",
 *   summary="Create a call timer",
 *   operationId="callTimerStore",
 *   tags={"Call Timers"},
 *   security={{"Bearer":{}}},
 *   @OA\RequestBody(@OA\JsonContent(required={"title"},
 *     @OA\Property(property="title", type="string"),
 *     @OA\Property(property="description", type="string"),
 *     @OA\Property(property="week_plan", type="object")
 *   )),
 *   @OA\Response(response=200, description="Call timer created")
 * )
 *
 * @OA\Post(
 *   path="/call-timers/{id}",
 *   summary="Update a call timer",
 *   operationId="callTimerUpdate",
 *   tags={"Call Timers"},
 *   security={{"Bearer":{}}},
 *   @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
 *   @OA\RequestBody(@OA\JsonContent(
 *     @OA\Property(property="title", type="string"),
 *     @OA\Property(property="description", type="string"),
 *     @OA\Property(property="week_plan", type="object")
 *   )),
 *   @OA\Response(response=200, description="Call timer updated"),
 *   @OA\Response(response=404, description="Not found")
 * )
 *
 * @OA\Delete(
 *   path="/call-timers/{id}",
 *   summary="Delete a call timer",
 *   operationId="callTimerDestroy",
 *   tags={"Call Timers"},
 *   security={{"Bearer":{}}},
 *   @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
 *   @OA\Response(response=200, description="Call timer deleted"),
 *   @OA\Response(response=400, description="Timer is assigned to a campaign"),
 *   @OA\Response(response=404, description="Not found")
 * )
 */
class CallTimerController extends Controller
{
    // GET /call-timers
//    public function index(Request $request)
// {
//     $connection = "mysql_" . $request->auth->parent_id;

//     // pagination params
//     $perPage = (int) $request->get('per_page', 10); // default 10
//     $page = (int) $request->get('page', 1); // default page 1
//     $search = $request->get('search');

//     $query = CallTimer::on($connection);

//     // search filter
//     if (!empty($search)) {
//         $query->where(function ($q) use ($search) {
//             $q->where('title', 'LIKE', "%{$search}%")
//               ->orWhere('description', 'LIKE', "%{$search}%");
//         });
//     }

//     // total rows (before pagination)
//     $totalRows = $query->count();

//     // apply pagination
//     $timers = $query->orderBy('id', 'desc')
//                     ->skip(($page - 1) * $perPage)
//                     ->take($perPage)
//                     ->get();

//     return $this->successResponse("Call Timers", [
//         "total_rows" => $totalRows,
//         "per_page"   => $perPage,
//         "current_page" => $page,
//         "data"       => $timers->toArray()
//     ]);
// }
public function index(Request $request)
{
    $connection = "mysql_" . $request->auth->parent_id;

    $query = CallTimer::on($connection);

    // Apply search filter if provided
    $search = $request->get('search');
    if (!empty($search)) {
        $query->where(function ($q) use ($search) {
            $q->where('title', 'LIKE', "%{$search}%")
              ->orWhere('description', 'LIKE', "%{$search}%");
        });
    }

    // Total rows before pagination
    $totalRows = $query->count();

    // Apply pagination only if start and limit exist
    if ($request->has('start') && $request->has('limit')) {
        $start = (int) $request->get('start');
        $limit = (int) $request->get('limit');

        $timers = $query->orderBy('id', 'desc')
                        ->skip($start)
                        ->take($limit)
                        ->get();
    } else {
        // No pagination, get all
        $timers = $query->orderBy('id', 'desc')->get();
        $start = 0;
        $limit = $totalRows;
    }
   // ✅ User timezone (default fallback)
    $userTimezone = $request->auth->timezone ?? APP_DEFAULT_USER_TIMEZONE;

    // ✅ Convert created_at & updated_at for response
$timers = $timers->map(function ($timer) use ($userTimezone) {

   $dayMap = [
    'monday'    => 1,
    'tuesday'   => 2,
    'wednesday' => 3,
    'thursday'  => 4,
    'friday'    => 5,
    'saturday'  => 6,
    'sunday'    => 7,
];

// ✅ get today's day name
$todayName = strtolower(Carbon::now()->format('l')); // e.g. "wednesday"
$day = null;

if (!empty($timer->week_plan) && is_array($timer->week_plan)) {

    // ✅ If today exists in week_plan, use today
    if (isset($timer->week_plan[$todayName]) && isset($dayMap[$todayName])) {
        $day = $dayMap[$todayName];
    } else {
        // ✅ fallback: first available valid day
        foreach ($timer->week_plan as $dayName => $time) {
            $dayName = strtolower($dayName);
            if (isset($dayMap[$dayName])) {
                $day = $dayMap[$dayName];
                break;
            }
        }
    }
}


    return [
        'id'          => $timer->id,
        'title'       => $timer->title,
        'day'         => $day,              // 👈 BEFORE description
        'description' => $timer->description,
        'week_plan'   => $timer->week_plan,
        'created_at'  => convertToUserTimezone($timer->created_at, $userTimezone),
        'updated_at'  => convertToUserTimezone($timer->updated_at, $userTimezone),
    ];
});

    return $this->successResponse("Call Timers", [
        "total_rows"   => $totalRows,
        "start"        => $start,
        "limit"        => $limit,
        "data"         => $timers->toArray()
    ]);
}


    // GET /call-timers/{id}
    public function show(Request $request,$id)
    {

    $connection = "mysql_" . $request->auth->parent_id;

    $timer = CallTimer::on($connection)->find($id);


        if (!$timer) {
            return response()->json(['message' => 'Timer not found'], 404);
        }


            return $this->successResponse("View Call Times", $timer->toArray());

    }

    // POST /call-timers
    public function store(Request $request)
    {
        $this->validate($request, [
            'title' => 'required|string',
            'description' => 'nullable|string',
            'week_plan' => 'nullable|array'
        ]);

        $connection = "mysql_" . $request->auth->parent_id;

        $timer = CallTimer::on($connection)->create([
            'title' => $request->title,
            'description' => $request->description,
            'week_plan' => $request->week_plan,
        ]);

            return $this->successResponse("Added Call Times", $timer->toArray());

    }

    // PUT /call-timers/{id}
//     public function update(Request $request, $id)
// {
//     $connection = "mysql_" . $request->auth->parent_id;

//     $timer = CallTimer::on($connection)->find($id);

//     if (!$timer) {
//         return response()->json(['message' => 'Timer not found'], 404);
//     }

//     // Validate inputs
//     $this->validate($request, [
//         'title' => 'sometimes|string',
//         'description' => 'sometimes|string',
//         'week_plan' => 'sometimes|array',
//     ]);

//     // If week_plan is sent, update (merge with existing if needed)
//     if ($request->has('week_plan')) {
//         $existingPlan = $timer->week_plan ?? [];
//         $newPlan = array_merge($existingPlan, $request->week_plan);
//         $timer->week_plan = $newPlan;
//     }

//     if ($request->has('title')) {
//         $timer->title = $request->title;
//     }

//     if ($request->has('description')) {
//         $timer->description = $request->description;
//     }

//     $timer->save();

//     return $this->successResponse("Updated Call Times", $timer->toArray());
// }
public function update(Request $request, $id)
{
    $connection = "mysql_" . $request->auth->parent_id;

    $timer = CallTimer::on($connection)->find($id);

    if (!$timer) {
        return response()->json(['message' => 'Timer not found'], 404);
    }

    $this->validate($request, [
        'title' => 'sometimes|string',
        'description' => 'sometimes|string',
        'week_plan' => 'sometimes|array',
    ]);

    // Replace week_plan completely
    if ($request->has('week_plan')) {
        $timer->week_plan = $request->week_plan;
    }

    if ($request->has('title')) {
        $timer->title = $request->title;
    }

    if ($request->has('description')) {
        $timer->description = $request->description;
    }

    $timer->save();

    return $this->successResponse("Updated Call Times", $timer->toArray());
}


    // DELETE /call-timers/{id}
    public function destroy(Request $request,$id)
    {
        $connection = "mysql_" . $request->auth->parent_id;

    $timer = CallTimer::on($connection)->find($id);

        if (!$timer) {
            return response()->json(['message' => 'Timer not found'], 404);
        }
          // Check if any campaign is using this timer
    $assignedCampaigns = Campaign::on($connection)
        ->where('call_schedule_id', $id)
        ->where('is_deleted', 0)
        ->select('id', 'title')
        ->get();

    if ($assignedCampaigns->count() > 0) {
        return response()->json([
            'message' => 'Cannot delete timer. It is assigned to a campaign.',
            'campaigns' => $assignedCampaigns,
        ], 400); // 400 Bad Request
    }

        $timer->delete();


    return $this->successResponse("Timer deleted successfully", $timer->toArray());

    }
}
