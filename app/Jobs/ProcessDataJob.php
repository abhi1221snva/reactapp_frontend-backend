<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Bus\Dispatchable; // Correct namespace for Dispatchable in Laravel 5.8
use App\Model\Master\RvmQueueList;


class ProcessDataJob implements ShouldQueue
{
    //use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    use InteractsWithQueue, Queueable, SerializesModels;

    protected $data;

    public function __construct($data)
    {
        $this->data = $data;
    }

    public function handle()
    {
        $RvmQueueList =new RvmQueueList();
        $RvmQueueList->rvm_cdr_log_id = 1;
        $RvmQueueList->status = $this->data;
        $RvmQueueList->save();


        // Process the data
        \Log::info('Processing data: ' . $this->data);
    }
}
