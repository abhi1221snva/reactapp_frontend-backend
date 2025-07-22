<?php

use Illuminate\Database\Seeder;

class DispositionTableSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $dispositions = [
            [
                "id" => 1,
                "title" => "No Answer",   
            ],
            [
                "id" => 2,
                "title" => "Not Interested",   
            ],
            [
                "id" => 3,
                "title" => "Sale Made",   
            ],
            [
                "id" => 4,
                "title" => "Do Not Call",   
            ],
            [
                "id" => 5,
                "title" => "Busy",   
            ],
            [
                "id" => 6,
                "title" => "Wrong Number",   
            ],
            [
                "id" => 7,
                "title" => "Disconnected",   
            ],
            [
                "id" => 8,
                "title" => "Call Back",   
            ],
            
        ];

        foreach ( $dispositions as $disposition ) {
            $clients = \App\Model\Master\Client::all();
            foreach ( $clients as $client ) {
                $addDisosition = \App\Model\Client\Disposition::on("mysql_".$client->id)->find($disposition["id"]);
                if (empty($addDisosition)) {
                    echo "Adding {$disposition["id"]} to client_{$client->id}.dispositions\n";
                    $addDisosition = new \App\Model\Client\Disposition ([
                        "id" => $disposition["id"],
                        "title" => $disposition["title"]
                    ]);
                    $addDisosition->setConnection("mysql_".$client->id);
                    $addDisosition->save();
                }
            }
        }
    }
}
