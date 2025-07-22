<?php

use Illuminate\Database\Seeder;

class CrmLeadStatusSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $lead_status = [
            [
                "id" => 1,
                "title" => "New Lead",   
                "lead_title_url" => "new_lead", 
                "color_code"=>"#ff0000",
                "image"=>"fa-th",
                "display_order"=>0,
                "view_on_dashboard"=>"0",


            ],
            [
                "id" => 2,
                "title" => "App Out", 
                "lead_title_url" => "app_out", 
                "color_code"=>"#ff0000",
                "image"=>"fa-th",
                "display_order"=>0,
                "view_on_dashboard"=>"1",

  
            ],
            [
                "id" => 3,
                "title" => "SPANISH SPEAKING ONLY",  
                "lead_title_url" => "spanish_speaking_only",  
                "color_code"=>"#ff0000",
                "image"=>"fa-th",
                "display_order"=>0,
                "view_on_dashboard"=>"1",


 
            ],
            [
                "id" => 4,
                "title" => "Missing Docs/info", 
                "lead_title_url" => "missing_docs",   
                "color_code"=>"#ff0000",
                "image"=>"fa-th",
                "display_order"=>0,
                "view_on_dashboard"=>"1",

  
            ],
            [
                "id" => 5,
                "title" => "Docs In", 
                "lead_title_url" => "docs_in",   
                "color_code"=>"#ff0000",
                "image"=>"fa-th",
                "display_order"=>0,
                "view_on_dashboard"=>"1",

            ],
            [
                "id" => 6,
                "title" => "Submitted to Non-MCA", 
                "lead_title_url" => "submitted_to_non-mca",   
                "color_code"=>"#ff0000",
                "image"=>"fa-th",
                "display_order"=>0,
                "view_on_dashboard"=>"1",

            ],  
             [
                "id" => 7,
                "title" => "Submitted", 
                "lead_title_url" => "submitted",   
                "color_code"=>"#ff0000",
                "image"=>"fa-th",
                "display_order"=>0,
                "view_on_dashboard"=>"1",

            ],  
             [
                "id" => 8,
                "title" => "Approved", 
                "lead_title_url" => "approved",   
                "color_code"=>"#ff0000",
                "image"=>"fa-th",
                "display_order"=>0,
                "view_on_dashboard"=>"1",

            ],  
             [
                "id" => 9,
                "title" => "Contract Out", 
                "lead_title_url" => "contract_out",   
                "color_code"=>"#ff0000",
                "image"=>"fa-th",
                "display_order"=>0,
                "view_on_dashboard"=>"1",

            ],  
             [
                "id" => 10,
                "title" => "Contract In", 
                "lead_title_url" => "contract_in",   
                "color_code"=>"#ff0000",
                "image"=>"fa-th",
                "display_order"=>0,
                "view_on_dashboard"=>"1",

            ],   
            [
                "id" => 11,
                "title" => "Funded", 
                "lead_title_url" => "funded",   
                "color_code"=>"#ff0000",
                "image"=>"fa-th",
                "display_order"=>0,
                "view_on_dashboard"=>"1",

            ],   
            [
                "id" => 12,
                "title" => "Declined", 
                "lead_title_url" => "declined",   
                "color_code"=>"#ff0000",
                "image"=>"fa-th",
                "display_order"=>0,
                "view_on_dashboard"=>"1",

            ],   
            [
                "id" => 13,
                "title" => "Merchant Declined Offer", 
                "lead_title_url" => "merchant_declined_offer",   
                "color_code"=>"#ff0000",
                "image"=>"fa-th",
                "display_order"=>0,
                "view_on_dashboard"=>"1",

            ],   
            [
                "id" => 14,
                "title" => "LOC", 
                "lead_title_url" => "loc",   
                "color_code"=>"#ff0000",
                "image"=>"fa-th",
                "display_order"=>0,
                "view_on_dashboard"=>"0",

            ],  
             [
                "id" => 15,
                "title" => "Revisit", 
                "lead_title_url" => "revisit",   
                "color_code"=>"#ff0000",
                "image"=>"fa-th",
                "display_order"=>0,
                "view_on_dashboard"=>"1",

            ],  
             [
                "id" => 16,
                "title" => "Wood", 
                "lead_title_url" => "wood",   
                "color_code"=>"#ff0000",
                "image"=>"fa-th",
                "display_order"=>0,
                "view_on_dashboard"=>"1",

            ],   
       
            
        ];

        foreach ($lead_status as $lead) {
            $clients = \App\Model\Master\Client::all();
        
            foreach ($clients as $client) {
                // Check if the label already exists in the client's database
                $addLead = \App\Model\Client\CrmLeadStatus::on("mysql_". $client->id)->find($lead["id"]);
        
                if (empty($addLead)) {
                    echo "Adding {$lead["id"]} to client_{$client->id}.label\n";
        
                    // Using firstOrCreate to add the label only if it doesn't exist
                    \App\Model\Client\CrmLeadStatus::on("mysql_". $client->id)->firstOrCreate(
                        ['id' => $lead["id"]],
                        [
                            'title' => $lead["title"],
                            'lead_title_url' => $lead["lead_title_url"],
                            'color_code' => $lead["color_code"],
                            'image' => $lead["image"],
                            'display_order' => $lead["display_order"],
                            'view_on_dashboard' => $lead["view_on_dashboard"],


                        ]
                    );
                } else {
                    echo "Label {$lead["id"]} already exists in client_{$client->id}.label\n";
                }
            }
        }
        
    }
}
