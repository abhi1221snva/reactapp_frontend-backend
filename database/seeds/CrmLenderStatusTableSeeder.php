<?php

use Illuminate\Database\Seeder;
use Carbon\Carbon;
use App\Model\Master\Client;
use App\Model\Client\LenderStatus;

class CrmLenderStatusTableSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $statuses = [
            [
                
                'title' => 'Approved', 
                'status' => 1, 
                'color'=> '#00c04b',
                'created_at' => Carbon::now(), 
                'updated_at' => Carbon::now()
            ],
            [
            
                'title' => 'Waiting', 
                'status' => 1, 
                'color'=> '#e8e337',
                'created_at' => Carbon::now(), 
                'updated_at' => Carbon::now()
            ],
            [
                
                'title' => 'Declined', 
                'status' => 1, 
                'color'=> '#fb3b1e',
                'created_at' => Carbon::now(), 
                'updated_at' => Carbon::now()
            ],
         
           
           
        ];

        // Fetch all clients that are not deleted
        $clients = Client::where('is_deleted', 0)->get();

        // Loop through each client
        foreach ($clients as $client) {
            // Loop through each status
            foreach ($statuses as $status) {
                // Check if the status already exists for the client
                $existingStatus = LenderStatus::on("mysql_". $client->id)->where('title', $status['title'])->first();

                // If the status doesn't exist, insert it
                if (!$existingStatus) {
                    LenderStatus::on("mysql_". $client->id)->create($status);
                    echo "Inserted {$status['title']} for client {$client->id}\n";
                } else {
                    echo "{$status['title']} already exists for client {$client->id}, can't insert again\n";
                }
            }
        }
    }
    }

