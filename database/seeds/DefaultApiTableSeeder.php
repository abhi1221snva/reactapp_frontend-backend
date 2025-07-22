<?php

use Illuminate\Database\Seeder;

class DefaultApiTableSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {

        $apis = [
            [
                "id" => 1,
                "title" => "API",  
                "url" =>"https://www.test.com/",
                "campaign_id" => '0',
                "is_default" => '1'
            ],
        ];

        foreach ( $apis as $api ) {
            $clients = \App\Model\Master\Client::all();
            foreach ( $clients as $client ) {
                $addApi = \App\Model\Api::on("mysql_".$client->id)->where('campaign_id','0')->get()->all();
                if (empty($addApi)) {
                    echo "Adding {$api["id"]} to client_{$client->id}.api\n";
                    $addApi = new \App\Model\Api ([
                        "title" => $api["title"],
                        "url" => $api['url'],
                        "campaign_id" => $api['campaign_id'],
                        "is_default" => $api['is_default']
                    ]);
                    $addApi->setConnection("mysql_".$client->id);
                    $addApi->save();
                }
            }
        }

    }
}
