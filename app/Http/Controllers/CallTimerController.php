<?php

namespace App\Http\Controllers;

use App\Model\CallTimer;
use Illuminate\Http\Request;

class CallTimerController extends Controller
{
    // GET /call-timers
   public function index(Request $request)
{
    $connection = "mysql_" . $request->auth->parent_id;

    // pagination params
    $perPage = (int) $request->get('per_page', 10); // default 10
    $page = (int) $request->get('page', 1); // default page 1
    $search = $request->get('search');

    $query = CallTimer::on($connection);

    // search filter
    if (!empty($search)) {
        $query->where(function ($q) use ($search) {
            $q->where('title', 'LIKE', "%{$search}%")
              ->orWhere('description', 'LIKE', "%{$search}%");
        });
    }

    // total rows (before pagination)
    $totalRows = $query->count();

    // apply pagination
    $timers = $query->orderBy('id', 'desc')
                    ->skip(($page - 1) * $perPage)
                    ->take($perPage)
                    ->get();

    return $this->successResponse("Call Timers", [
        "total_rows" => $totalRows,
        "per_page"   => $perPage,
        "current_page" => $page,
        "data"       => $timers->toArray()
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
    public function update(Request $request, $id)
{
    $connection = "mysql_" . $request->auth->parent_id;

    $timer = CallTimer::on($connection)->find($id);

    if (!$timer) {
        return response()->json(['message' => 'Timer not found'], 404);
    }

    // Validate inputs
    $this->validate($request, [
        'title' => 'sometimes|string',
        'description' => 'sometimes|string',
        'week_plan' => 'sometimes|array',
    ]);

    // If week_plan is sent, update (merge with existing if needed)
    if ($request->has('week_plan')) {
        $existingPlan = $timer->week_plan ?? [];
        $newPlan = array_merge($existingPlan, $request->week_plan);
        $timer->week_plan = $newPlan;
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

        $timer->delete();


    return $this->successResponse("Timer deleted successfully", $timer->toArray());

    }
}
