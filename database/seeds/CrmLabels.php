<?php

use Illuminate\Database\Seeder;
use App\Model\Client\CrmLabel;


class CrmLabels extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $clients = \App\Model\Master\Client::where('is_deleted','0')->get()->all();

        $arrLabels = [
            ["title" => "First Name","label_title_url" => "first_name", "data_type" => "text","values" => "","required" => "1","display_order" => "1","column_name" => "first_name","status" => "1",'label_type' => 'system','edit_mode'=> 1],
            ["title" => "Last Name","label_title_url" => "last_name","data_type" => "text","values" => "","required" => "1","display_order" => "2","column_name" => "last_name","status" => "1",'label_type' => 'system','edit_mode'=> 1],
            ["title" => "Email","label_title_url" => "email","data_type" => "email","values" => "","required" => "1","display_order" => "3","column_name" => "email","status" => "1",'label_type' => 'system','edit_mode'=> 1],
            ["title" => "Mobile","label_title_url" => "mobile","data_type" => "phone_number","values" => "","required" => "1","display_order" => "4","column_name" => "phone_number","status" => "1",'label_type' => 'system','edit_mode'=> 1],
            ["title" => "Gender","label_title_url" => "gender","data_type" => "select_option","values" => '["male","female","other"]',"required" => "0","display_order" => "5","column_name" => "gender","status" => "1",'label_type' => 'system','edit_mode'=> 1],
            ["title" => "DOB","label_title_url" => "dob","data_type" => "date","values" => "","required" => "0","display_order" => "6","column_name" => "dob","status" => "1",'label_type' => 'system','edit_mode'=> 1],
            ["title" => "Country","label_title_url" => "country","data_type" => "text","values" => "","required" => "0","display_order" => "8","column_name" => "country","status" => "1",'label_type' => 'system','edit_mode'=> 1],
            ["title" => "State","label_title_url" => "state","data_type" => "text","values" => "","required" => "0","display_order" => "8","column_name" => "state","status" => "1",'label_type' => 'system','edit_mode'=> 1],
            ["title" => "City","label_title_url" => "city","data_type" => "text","values" => "","required" => "0","display_order" => "7","column_name" => "city","status" => "1",'label_type' => 'system','edit_mode'=> 1],
            ["title" => "Address","label_title_url" => "address","data_type" => "text","values" => "","required" => "0","display_order" => "10","column_name" => "address","status" => "1",'label_type' => 'system','edit_mode'=> 1],
            ["title" => "Legal Company Name","label_title_url" => "legal_company_name","data_type" => "text","values" => "","required" => "1","display_order" => "11","column_name" => "company_name","status" => "1",'label_type' => 'system','edit_mode'=> 1],
            ["title" => "Application Url","label_title_url" => "unique_url","data_type" => "text","values" => "","required" => "0","display_order" => "11","column_name" => "unique_url","status" => "1",'label_type' => 'system','edit_mode'=>'0']
        ];

        foreach ( $clients as $client ) {
            foreach ($arrLabels as $key => $arrLabel) {
                $objLabelFound = CrmLabel::on("mysql_$client->id")->where('label_title_url', $arrLabel["label_title_url"])->get()->first();
                if (!empty($objLabelFound)) {
                    $objLabelFound->update($arrLabel);
                } else {
                    $objLabel = new CrmLabel();
                    $objLabel->setConnection("mysql_$client->id");
                    $objLabel->title = $arrLabel["title"];
                    $objLabel->label_title_url = $arrLabel["label_title_url"];
                    $objLabel->data_type = $arrLabel["data_type"];
                    $objLabel->values = $arrLabel["values"];
                    $objLabel->required = $arrLabel["required"];
                    $objLabel->display_order = $arrLabel["display_order"];
                    $objLabel->column_name = $arrLabel["column_name"];
                    $objLabel->status = $arrLabel["status"];
                    $objLabel->label_type = $arrLabel["label_type"];
                    $objLabel->edit_mode = $arrLabel["edit_mode"];


                    $objLabel->saveOrFail();
                }
            }
        }
    }
}
