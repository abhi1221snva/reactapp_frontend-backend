<?php

use Illuminate\Database\Seeder;

class CrmDocumentTypeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $doc_type = [
            [
                "id" => 1,
                "title" => "Equipment Quote",   
                "type_title_url" => "equipment_quote", 
                "values"=>"[]",
              

            ],
            [
                "id" => 2,
                "title" => "Other",   
                "type_title_url" => "other", 
                "values"=>"[]",
             
  
            ],
            [
                "id" => 3,
                "title" => "Credit Report",   
                "type_title_url" => "credit_report", 
                "values"=>"[]",
             
  
            ],
         
            [
                "id" => 4,
                "title" => "FCS",   
                "type_title_url" => "fcs", 
                "values"=>"[]",
             
  
            ],
            
            [
                "id" => 5,
                "title" => "EIN",   
                "type_title_url" => "ein", 
                "values"=>"[]",
             
  
            ],
            [
                "id" => 6,
                "title" => "Driver's License",   
                "type_title_url" => "driver_license", 
                "values"=>"[]",
             
  
            ],
            [
                "id" => 7,
                "title" => "Tax Return",   
                "type_title_url" => "tax_return", 
                "values"=>"[]",
             
  
            ],
            [
                "id" => 8,
                "title" => "Signed Application",   
                "type_title_url" => "signed_application", 
                "values"=>"[]",
             
  
            ],
            [
                "id" => 9,
                "title" => "Credit Card Processing",   
                "type_title_url" => "credit_card_processing", 
                "values"=>"[]",
             
  
            ],
            [
                "id" => 10,
                "title" => "Bank Statement",   
                "type_title_url" => "bank_statement", 
                "values"=>'["January","February","March","April","May","June","July","August","September","October","November","December"]',
             
  
            ],
            [
                "id" => 11,
                "title" => "Voided Check",   
                "type_title_url" => "voided_check", 
                "values"=>"[]",
             
  
            ],
       
            
        ];

        foreach ($doc_type as $doc) {
            $clients = \App\Model\Master\Client::all();
        
            foreach ($clients as $client) {
                // Check if the label already exists in the client's database
                $addDoc = \App\Model\Client\DocumentTypes::on("mysql_".$client->id)->find($doc["id"]);
        
                if (empty($addDoc)) {
                    echo "Adding {$doc["id"]} to client_{$client->id}.label\n";
        
                    // Using firstOrCreate to add the label only if it doesn't exist
                    \App\Model\Client\DocumentTypes::on("mysql_". $client->id)->firstOrCreate(
                        ['id' => $doc["id"]],
                        [
                            'title' => $doc["title"],
                            'type_title_url' => $doc["type_title_url"],
                            'values' => $doc["values"],
                           
                        ]
                    );
                } else {
                    echo "Label {$doc["id"]} already exists in client_{$client->id}.label\n";
                }
            }
        }
    }
}
