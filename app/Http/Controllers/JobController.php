<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessDataJob;
use Illuminate\Http\Request;
use Carbon\Carbon;

class JobController extends Controller
{
    public function sendJobs()
    {
        $dataToProcess = ['Task 1', 'Task 2', 'Task 3', 'Task 4']; // Example data

        foreach ($dataToProcess as $data) {


            dispatch((new ProcessDataJob($data))->delay(Carbon::now()->addSeconds(rand(1,1))));


           // ProcessDataJob::dispatch($data)
                //->delay(Carbon::now()->addSeconds(rand(0, 59))); // Delay to random seconds within a minute
        }

        return response()->json(['status' => 'Jobs sent!']);
    }
}
