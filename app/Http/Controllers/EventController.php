<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Model\Client\Event;
use Illuminate\Support\Facades\Log;

class EventController extends Controller
{
    public function index(Request $request)
    {
        try {
            $clientId = $request->auth->parent_id;
             $events = Event::on("mysql_$clientId")->get();
            $eventsArray=$events->toArray();

            return $this->successResponse("List of Events", $eventsArray);
        } catch (\Throwable $exception) {
            return $this->failResponse("Failed to events ", [$exception->getMessage()], $exception, $exception->getCode());
        }
       
    }
    public function add(Request $request)
{
    
    try {
        $start_date = $request->input('start_date');
        $end_date = $request->input('end_date');
        Log::info('reached', ['end_date'=>$end_date]);

        // Check if end date is less than start date
        if ($end_date < $start_date) {
            return $this->failResponse("End date cannot be less than start date");
        }

        $clientId = $request->auth->parent_id;
        // $clientId = 3;

        $events = new Event;
        $events->setConnection("mysql_$clientId");
        $events->user_id = $request->auth->id;
        $events->title = $request->input('title');
        $events->color = $request->input('color');
        $events->start_date = $start_date;
        $events->end_date = $end_date;
        $events->save();
        $eventsArray = $events->toArray();

        return $this->successResponse("Events Added", $eventsArray);
    } catch (\Throwable $exception) {
        return $this->failResponse("Failed to add events ", [$exception->getMessage()], $exception, $exception->getCode());
    }
}

    // public function add(Request $request)
    // {
    //     Log::info('reached',[$request->auth->id]);
    //     try {
    //         //$clientId = $request->auth->parent_id;
    //         $clientId = 3;
             
    //     $events=new Event;
    //     $events->setConnection("mysql_3");
    //     $events->user_id=$request->auth->id;
    //     $events->title=$request->input('title');
    //     $events->color=$request->input('color');
    //     $events->start_date=$request->input('start_date');
    //     $events->end_date=$request->input('end_date');
    //     $events->save();
    //     $eventsArray=$events->toArray();

    //         return $this->successResponse("Events Added", $eventsArray);
    //     } catch (\Throwable $exception) {
    //         return $this->failResponse("Failed to add events ", [$exception->getMessage()], $exception, $exception->getCode());
    //     }
       
    // }
public function delete(Request $request)
{
    Log::info('reached',[$request->all()]);
    $id=$request->event_id;
    $events = Event::on("mysql_" . $request->auth->parent_id)->findOrFail($id);
    $events->delete();
    if ($events) {
        return $this->successResponse("Event  Deleted", $events->toArray());
    } else {
        return $this->failResponse("Failed to delete the Event ", [
            "Unkown"
        ]);
    }
}

public function update(Request $request)
{
    Log::info('reached',[$request->all()]);
    try {
       
            

            $eventId = $request->input('event_id');
            $date_time = date('Y-m-d h:i:s');

            // Retrieve validated data
            $data = $request->only([
                'title', 'color', 'start_date', 'end_date'
            ]);


            // Use Eloquent to update the model
            $events = Event::on("mysql_" . $request->auth->parent_id)->find($eventId);

            if ($events) {
                $events->update($data);

                return [
                    'success' => true,
                    'message' => 'Event updated successfully.',
                ];
          
        }

        return [
            'success' => false,
            'message' => 'Invalid input or events not found.',
        ];
    } catch (\Exception $e) {
        Log::error($e->getMessage());
        return [
            'success' => false,
            'message' => 'An error occurred while processing the request.',
        ];
    }
}

public function show(Request $request, $id)
{
    try {
        $events = Event::on("mysql_" . $request->auth->parent_id)->where('id',$id)->first();
Log::info('reached',['events'=>$events]);
        if ($events) {
            return response()->json([
                'success' => true,
                'message' => 'Event Details.',
                'data' => $events, // Return the event data
            ]);
        } else {
            return response()->json([
                'success' => false,
                'message' => 'Event not found.',
            ], 404); // Return a 404 status code for not found
        }
    } catch (\Exception $e) {
        Log::error($e->getMessage());
        return response()->json([
            'success' => false,
            'message' => 'An error occurred while processing the request.',
        ], 500); // Return a 500 status code for server error
    }
}

}
