<?php

use Illuminate\Database\Seeder;

class LabelTableSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $labels = [
            [
                "id" => 1,
                "title" => "First Name",   
            ],
            [
                "id" => 2,
                "title" => "Last Name",   
            ],
            [
                "id" => 3,
                "title" => "Legal Company Name",   
            ],
            [
                "id" => 4,
                "title" => "Address",   
            ],
            [
                "id" => 5,
                "title" => "Work Phone",   
            ],
            [
                "id" => 6,
                "title" => "Mobile",   
            ],
            [
                "id" => 7,
                "title" => "City",   
            ],
            [
                "id" => 8,
                "title" => "State",   
            ],
            [
                "id" => 9,
                "title" => "Zip",   
            ],
            [
                "id" => 10,
                "title" => "Funding Amount",   
            ],
            [
                "id" => 11,
                "title" => "Email",   
            ],
            [
                "id" => 12,
                "title" => "Business Type",   
            ],
            [
                "id" => 13,
                "title" => "Monthly Revenue",   
            ],
            [
                "id" => 14,
                "title" => "Lead Source",   
            ],
            [
                "id" => 15,
                "title" => "Credit Score",   
            ],
            [
                "id" => 16,
                "title" => "Business Age",   
            ],
            [
                "id" => 17,
                "title" => "Annual Revenue",   
            ],
            [
                "id" => 18,
                "title" => "Factor Rate",   
            ],
            
        ];

        foreach ($labels as $label) {
            $clients = \App\Model\Master\Client::all();
    
            foreach ($clients as $client) {
                // Check if the label already exists in the client's database
                $addLabels = \App\Model\Client\Label::on("mysql_" . $client->id)->find($label["id"]);
    
                if (empty($addLabels)) {
                    echo "Adding {$label["id"]} to client_{$client->id}.label\n";
    
                    // Using firstOrCreate to add the label only if it doesn't exist
                    \App\Model\Client\Label::on("mysql_" . $client->id)->firstOrCreate(
                        ['id' => $label["id"]],
                        ['title' => $label["title"]]
                    );
                } else {
                    echo "Label {$label["id"]} already exists in client_{$client->id}.label\n";
                }
            }
        }
    }
}
