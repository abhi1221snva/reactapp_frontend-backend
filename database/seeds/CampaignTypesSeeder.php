<?php

use App\Model\Master\Client;
use App\Model\Client\CampaignTypes;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class CampaignTypesSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $arrQuestions = [
            ['title' => 'Super Power Dial', 'title_url' => 'super_power_dial', 'status' => '1'],
            ['title' => 'Predictive Dial', 'title_url' => 'predictive_dial', 'status' => '0'],
            ['title' => 'Outbound AI', 'title_url' => 'outbound_ai', 'status' => '0']

        ];
        $clients = Client::all();
        foreach ( $clients as $client ) {
            
            DB::connection("mysql_" . $client->id)->statement("DELETE FROM campaign_types;");
            DB::connection("mysql_" . $client->id)->statement("ALTER TABLE campaign_types AUTO_INCREMENT = 1");

            foreach ($arrQuestions as $key => $arrQuestion) {
            $CampaignTypes = new CampaignTypes();
            $CampaignTypes->setConnection('mysql_'.$client->id);
            $CampaignTypes->title = $arrQuestion['title'];
            $CampaignTypes->title_url = $arrQuestion['title_url'];
            $CampaignTypes->status = $arrQuestion['status'];
            $CampaignTypes->saveOrFail();
        }
        }


    }
}
