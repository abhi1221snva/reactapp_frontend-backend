<?php

use Illuminate\Database\Seeder;
use App\Model\Master\Client;
use Illuminate\Support\Facades\DB;
use App\Model\Client\Campaign;

class UpdateCampaignsTableSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
    
            // Fetch clients where is_deleted is 0
        $clients = Client::where('is_deleted', '0')->get();

        // Define the area codes
        $areaCode3 = 'area_code_3';
        $areaCode4 = 'area_code_4';
        $areaCode5 = 'area_code_5';
        $areaCodeRandom = 'area_code_random';

        // Update campaigns for each client
        foreach ($clients as $client) {
            Campaign::on("mysql_". $client->id)
                ->whereIn('caller_id', [$areaCode3,$areaCode4,$areaCode5])
                ->update(['caller_id' => $areaCodeRandom]);
        }
        }
    }

