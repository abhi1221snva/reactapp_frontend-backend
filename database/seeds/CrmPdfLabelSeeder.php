<?php

use Illuminate\Database\Seeder;
use App\Model\Client\CrmPdfLabels;


class CrmPdfLabelSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
     {
        $clients = \App\Model\Master\Client::where('is_deleted','0')->get()->all();


        $json = '{
            "message": "",
            "response": {
                "business_information": {
                    "legal_corporate_name": "",
                    "dba": "",
                    "physical_address": "",
                    "city": "",
                    "state": "",
                    "zip": "",
                    "telephone": "",
                    "fax": "",
                    "federal_tax_id": "",
                    "date_business_started": "",
                    "length_of_ownership": "",
                    "website": "",
                    "type_of_entity": "",
                    "email_address": "",
                    "type_of_business": [],
                    "product_service_sold": "",
                    "use_of_proceeds": "",
                    "gross_annual_sales": ""
                },
                "owner_officer_information": {
                    "owner_first_name": "",
                    "owner_last_name": "",
                    "ownership_percentage": "",
                    "home_address": "",
                    "city": "",
                    "state": "",
                    "zip": "",
                    "ssn": "",
                    "date_of_birth": "",
                    "home_phone": "",
                    "cell_phone": ""
                },
                "partner_information": {
                    "partner_first_name": "",
                    "partner_last_name": "",
                    "ownership_percentage": "",
                    "home_address": "",
                    "city": "",
                    "state": "",
                    "zip": "",
                    "ssn": "",
                    "date_of_birth": "",
                    "home_phone": "",
                    "cell_phone": ""
                },
                "business_property_information": {
                    "business_landlord_or_mortgage_bank": "",
                    "contact_name_or_account": "",
                    "phone": "",
                    "own_lease": "",
                    "monthly_rent_or_mortgage": ""
                },
                "credit_card_information": {
                    "credit_card_processing_terminal_model": "",
                    "number_of_terminals": "",
                    "average_monthly_volume": "",
                    "state_of_incorporation": "",
                    "accepted_payment_methods": []
                },
                "prior_current_working_capital": {
                    "balance": ""
                },
                "bank_information": {
                    "previous_month_business_deposits": "",
                    "two_months_ago_business_deposits": "",
                    "three_months_ago_business_deposits": "",
                    "four_months_ago_business_deposits": "",
                    "previous_month_neg_days": "",
                    "two_months_ago_neg_days": "",
                    "three_months_ago_neg_days": "",
                    "four_months_ago_neg_days": ""
                },
                "applicant_information": {
                    "applicants_name": "",
                    "sign_date": ""
                }
            }
        }';

        // Convert JSON to PHP array and access only the 'response' key
        $array = json_decode($json, true)['response'];

        // Helper function to flatten JSON
        $flattenJson = function ($data, $parentKey = '') use (&$flattenJson) {
            $items = [];
            foreach ($data as $key => $value) {
                $newKey = $parentKey ? $parentKey . '.' . $key : $key;
                if (is_array($value)) {
                    $items = array_merge($items, $flattenJson($value, $newKey));
                } else {
                    $items[] = ['pdf_label' => $newKey];
                }
            }
            return $items;
        };

        // Flatten the JSON data
        $arrLabels = $flattenJson($array);

       // echo "<pre>"; print_r($flattenedData); die;


       

        foreach ( $clients as $client ) {

           /*  foreach ($flattenedData as $row) {
            CrmPdfLabels::on("mysql_$client->id")->create($row);
        }*/
            foreach ($arrLabels as $key => $arrLabel) {
                $objLabelFound = CrmPdfLabels::on("mysql_$client->id")->where('pdf_label', $arrLabel["pdf_label"])->get()->first();
                if (!empty($objLabelFound)) {
                    $objLabelFound->update($arrLabel);
                } else {
                    $objLabel = new CrmPdfLabels();
                    $objLabel->setConnection("mysql_$client->id");
                    $objLabel->pdf_label = $arrLabel["pdf_label"];
                    $objLabel->saveOrFail();
                }
            }
        }
    }
}
