<?php

namespace App\Console\Commands;

use App\Model\Master\Client;
use App\Jobs\SendFaxEmailJob;
use App\Model\Fax;
use Illuminate\Console\Command;

class CheckFaxStatusCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'command:check-fax-status {--clientId=}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Send fax status to clients';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $clientId = $this->option('clientId');
        if ($clientId) {
            $this->info("Checking Fax Status For Client ".$clientId);
            $this->getClientFax($clientId);
        } else {
            $clients = \App\Model\Master\Client::all();
            foreach ( $clients as $client ) {
                $this->info("Checking Fax Status For Client ".$client->id);
                $this->getClientFax($client->id);
            }
        }
    }
    
    /**
     * Get client wise fax
     * @param type $fax
     */
    private function getClientFax($clientId) {
        $objFax = Fax::on("mysql_" . $clientId)->where('faxstatus', 1)
                ->where('delivery_status', "!=", 'COMPLETE')->get();
        foreach($objFax as $fax) {
            if($fax->faxstatus == 1 
                && $fax->ref_id != null && $fax->ref_id != '') {
                $res = $this->checkFaxStatusFromApi($fax->ref_id);
                $this->info("Status Changed For Fax id ".$fax->id." - Ref Id ".$fax->ref_id);
                if(isset($res['status']) && $res['status'] == true) {
                    $this->info("Status Changed For Fax id ".$fax->id." - Ref Id ".$fax->ref_id."  Client Id ".$clientId);
                    Fax::on("mysql_" . $clientId)->where("id", $fax->id)->update(['delivery_status' => $res['fax_status']]);
                    //send mail on fac status complete
                } else {}                    
            }
        }
    }
    
    /**
    * checkFaxStatusFromAPi
    * @param type $request
    * @return string
    */
    private function checkFaxStatusFromApi($ref_id) {
        $result= [];
        $json_data_to_send["uuid"] = $ref_id;
        $url = env('DID_SALE_API_URL') . "products/CheckFaxStatus";
        $ch = curl_init(); 
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($json_data_to_send));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
        curl_setopt($ch, CURLOPT_HEADER, FALSE);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array("Accept: application/json","Authorization: Basic ".base64_encode(env('DID_SALE_SERVICE_KEY').':'.env('DID_SALE_SERVICE_TOKEN'))));
        $response = curl_exec($ch);
        $response = json_decode($response, 1);
        return $response;
    }
}
