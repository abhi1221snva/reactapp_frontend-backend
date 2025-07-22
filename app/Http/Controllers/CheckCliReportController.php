<?php

namespace App\Http\Controllers;
use App\Model\Client\Campaign;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Model\Client\ExtensionLive;
use App\Model\Client\CliReport;
use App\Model\Master\CnamCliReport;
use App\Model\Dialer;
use Illuminate\Support\Facades\DB;
use App\Model\Master\Client;
use App\Model\Master\Did;
use DateTime;
use App\Model\Cron;


class CheckCliReportController extends Controller {
    private $request;
    public function __construct(Request $request, Dialer $dialer) {
        $this->request = $request;
        $this->model = $dialer;
    }

    public function index() {
        $this->validate($this->request, ['cli' => 'required|string|max:255','cnam' => 'required|string|max:255','token' => 'required|string|max:255','created_date' => 'required|string|max:255']);

        try {
            $token = $this->request->token;
            $tokenENV = env('PREDICTIVE_CALL_TOKEN');

            if($tokenENV == $_GET['token']) {
                $cli = $this->request->cli;
                $cnam = $this->request->cnam;
                $created_date = $this->request->created_date;

                $find_cli = Did::on("master")->where('cli',$cli)->get()->first();
                
                if(empty($find_cli)) {
                    //cnam master cli report
                    $CnamCliReport = CnamCliReport::on('master')->where('cli',$cli)->where('status','0')->get()->first();

                   // echo "<pre>";print_r($CnamCliReport);die;

                    if(!empty($CnamCliReport)) {
                        $CnamCliReport = CnamCliReport::findOrFail($CnamCliReport->id);
                        $CnamCliReport->setConnection("master");
                        $CnamCliReport->cli = $cli;
                        $CnamCliReport->cnam = $cnam;
                        $CnamCliReport->status = 1;

                        $CnamCliReport->created_date = $created_date;
                        $CnamCliReport->saveOrFail();

                        $CliReport = new CliReport();
                        $CliReport->setConnection("mysql_$CnamCliReport->parent_id");
                        $CliReport->cli = $cli;
                        $CliReport->cnam = $cnam;
                        $CliReport->created_date = $created_date;
                        $CliReport->saveOrFail();

                        return $this->successResponse("CLI Added Successfully", $CnamCliReport->toArray());
                    }
                }

                if(!empty($find_cli)) {
                    $clientId = $find_cli->parent_id;
                    $CliReport = new CliReport();
                    $CliReport->setConnection("mysql_$clientId");
                    $CliReport->cli = $cli;
                    $CliReport->cnam = $cnam;
                    $CliReport->created_date = $created_date;
                    $CliReport->saveOrFail();

                    //cnam master cli report

                    $CnamCliReport = CnamCliReport::on('master')->where('cli',$cli)->where('status','0')->get()->first();

                    if(!empty($CnamCliReport)) {
                        $CnamCliReport = CnamCliReport::findOrFail($CnamCliReport->id);
                        $CnamCliReport->setConnection("master");
                        $CnamCliReport->cli = $cli;
                        $CnamCliReport->cnam = $cnam;
                        $CnamCliReport->status = 1;
                        $CnamCliReport->created_date = $created_date;
                        $CnamCliReport->parent_id = $clientId;
                        $CnamCliReport->saveOrFail();
                    }
                    return $this->successResponse("CLI Added Successfully", $CliReport->toArray());
                }
                else {
                    return $this->failResponse("CLI not found", []);
                }
            }
            else {
                return $this->failResponse("Token is Invalid", []);
            }
        }

        catch (\Exception $exception) {
            return $this->failResponse("Failed to create Lead Status ", [$exception->getMessage()], $exception, 500);
        }
    }
}
