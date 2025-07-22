<?php

namespace App\Http\Controllers;
use App\Model\Client\Campaign;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Model\Client\ExtensionLive;
use App\Model\Client\CliReport;
use App\Model\Client\wallet;
use App\Model\Master\CnamCliReport;
use App\Model\Dialer;
use Illuminate\Support\Facades\DB;
use App\Model\Master\Client;
use App\Model\User;
use DateTime;
use App\Model\Cron;
use App\Model\Client\Cdr;
use App\Model\Client\CdrArchive;
use App\Model\Client\UserPackage;
use App\Model\Master\ClientPackage;
use App\Model\Master\CountryWisePackageRates;


class BillingChargeController extends Controller {
    private $request;
    public function __construct(Request $request, Dialer $dialer) {
        $this->request = $request;
        $this->model = $dialer;
    }

    public function index() {
        die;
        $this->validate($this->request, ['extension' => 'required|int','lead_id' => 'required|int','token' => 'required|string|max:255','duration' => 'required|int','cdr_id' => 'required|int']);

        try {
            $token = $this->request->token;
            $tokenENV = env('PREDICTIVE_CALL_TOKEN');

            if($tokenENV == $token) {
                $extension = $this->request->extension;
                $lead_id = $this->request->lead_id;
                $duration = $this->request->duration;
                $cdr_id = $this->request->cdr_id;

                if(!empty($_POST['country_id']))
                {
                $country_id = $_POST['country_id'];

                }
                else
                {
                $country_id = 1;

                }




                $user_details = User::on("master")->where('extension',$extension)->orWhere('alt_extension',$extension)->get()->first();

                if(!empty($user_details)) {
                $clientId = $user_details->parent_id;

               

                $user_package = UserPackage::on("mysql_$clientId")->where('user_id',$user_details->id)->orderBy('id','desc')->get()->first();


                 $client_package_details = ClientPackage::on("master")->where('id',$user_package->client_package_id)->orderBy('id','desc')->get()->first();

                 //echo $user_package1->package_key;die;

                $country_wise_rate = CountryWisePackageRates::on('master')->where('phone_code',$country_id)->where('package_key',$client_package_details->package_key)->get()->first();


                if(!empty($country_wise_rate ))

                    $rate = $country_wise_rate->rate_six_by_six_sec / 10;
                else
                    return $this->failResponse("country wise rate not found", []);

                    $cdr_details = Cdr::on("mysql_$clientId")->where('lead_id',$lead_id)->where('id',$cdr_id)->orderBy('id','desc')->get()->first();
                    
                    if(!empty($cdr_details)) {
                   // $cdr_id = $cdr_details->id;



                        $six_sec = $duration / 6;

                        if(is_int($six_sec))
                        {
                            $time = explode('.',$six_sec);
                            $call_time = $time[0];
                        }
                        else
                        {
                            $time = explode('.',$six_sec);
                            $call_time = $time[0]+1;
                        }
                        

                        //$time = explode('.',$six_sec);
                        //$call_time = $time[0]+1;
                        
                        $rate = $rate;
                        $billable_charge = $rate * $call_time;


                        $cdr_update = Cdr::on("mysql_$clientId")->findOrFail($cdr_id);
                        $cdr_update->billable_charge = $billable_charge;
                        $cdr_update->save();

                         $wallet = wallet::on("mysql_$clientId")->get()->first();
                         $amount_balance = $wallet->amount;
                         $amount = round($amount_balance - $billable_charge,4);

                         $sql = "UPDATE wallet set amount = :amount WHERE currency_code = :currency_code";
                DB::connection("mysql_$clientId")->update($sql, array('currency_code' => 'USD', 'amount' => $amount));

                        
                        $wallet->amount = $amount;
                        $wallet->save();



                        return $this->successResponse("Cdr Lead Id Updated Successfully", $cdr_update->toArray());
                    }

                    else {
                        return $this->failResponse("Lead Id not found", []);
                    }
                }

                else {
                    return $this->failResponse("Extension not found", []);
                }
            }
            else {
                return $this->failResponse("Token is Invalid", []);
            }
        }

        catch (\Exception $exception) {
            return $this->failResponse("Failed to update Lead ID Status ", [$exception->getMessage()], $exception, 500);
        }
    }
}
