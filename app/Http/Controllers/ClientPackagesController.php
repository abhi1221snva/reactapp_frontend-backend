<?php

namespace App\Http\Controllers;

use App\Model\User;
use App\Model\Client\UserPackage;
use App\Model\Master\ClientPackage;
use App\Model\Master\Client;
use Illuminate\Support\Carbon;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use App\Model\Master\Package;
use \stdClass;
use Illuminate\Support\Facades\DB;
use App\Cart;





class ClientPackagesController extends Controller
{
    /**
     * Create a new controller instance.
     *
     * @return void
     */
    private $request;
    private $model;

    public function __construct(Request $request, User $user)
    {
        $this->request = $request;
        $this->model = $user;
    }
/**
 * @OA\Get(
 *     path="/active-client-plans",
 *     summary="Get active client plans",
 *     description="Fetch active client plans based on user's level and client association.",
 *     tags={"Subscriptions"},
 *     security={{"Bearer":{}}},
 *     @OA\Parameter(
 *         name="Authorization",
 *         in="header",
 *         required=true,
 *         description="Authorization token",
 *         @OA\Schema(type="string")
 *     ),
 *     @OA\Response(
 *         response=200,
 *         description="Successful response with client plans",
 *         @OA\JsonContent(
 *             type="object",
 *             @OA\Property(
 *                 property="message",
 *                 type="string",
 *                 example="All Available packages"
 *             ),
 *             @OA\Property(
 *                 property="data",
 *                 type="object",
 *                 additionalProperties={
 *                     @OA\Property(
 *                         property="client_id",
 *                         type="integer",
 *                         example=1
 *                     ),
 *                     @OA\Property(
 *                         property="client_name",
 *                         type="string",
 *                         example="Client ABC"
 *                     ),
 *                     @OA\Property(
 *                         property="package_key",
 *                         type="string",
 *                         example="package123"
 *                     ),
 *                     @OA\Property(
 *                         property="billed",
 *                         type="string",
 *                         example="monthly"
 *                     ),
 *                     @OA\Property(
 *                         property="package_name",
 *                         type="string",
 *                         example="Gold Package"
 *                     ),
 *                     @OA\Property(
 *                         property="start_time",
 *                         type="string",
 *                         format="date",
 *                         example="2024-05-01"
 *                     ),
 *                     @OA\Property(
 *                         property="end_time",
 *                         type="string",
 *                         format="date",
 *                         example="2025-05-01"
 *                     ),
 *                     @OA\Property(
 *                         property="quantity",
 *                         type="integer",
 *                         example=10
 *                     ),
 *                     @OA\Property(
 *                         property="assigned",
 *                         type="array",
 *                         @OA\Items(type="integer", example=1)
 *                     )
 *                 }
 *             )
 *         )
 *     ),
 *     @OA\Response(
 *         response=400,
 *         description="Bad request, incorrect data or authorization error",
 *         @OA\JsonContent(
 *             @OA\Property(property="message", type="string", example="Invalid request")
 *         )
 *     )
 * )
 */


    public function activeClientPlans(Request $request)
    {
        $arrClientPackages = [];
        if($request->auth->level > 7)
        {
        $clients = Client::all();
        foreach($clients->toArray() as $key => $selectClient)
        {
            //fetch packages
            $packages           = Package::all()->toArray();
            $packagesRekeyed    = self::rekeyArray( $packages, 'key' );

            //fetch client_packages
            $clientId = $selectClient['id'];
            //fetch client_packages
            $clientPackages         = ClientPackage::where('client_id','=', $clientId)->where('end_time', '>=', date('Y-m-d h:i:s'))->get()->toArray();
            $clientPackagesRekeyed  = self::rekeyArray( $clientPackages, 'id' );
            //return $this->successResponse("All Available packages", $clientPackagesRekeyed );

            //fetch client_xxx.user_packages
            $userPackages = DB::connection('mysql_'.$clientId)->table('user_packages')->get()->toArray();
            $arrClientPackageAvailability = $this->getUsersByPackage( $userPackages );

            foreach ($clientPackagesRekeyed as $clientPackage)
            {
                $arrClientPackages[$clientId][$clientPackage['id']]['client_id']     = $clientId;
                $arrClientPackages[$clientId][$clientPackage['id']]['client_name']     = $selectClient['company_name'];
                
                $arrClientPackages[$clientId][$clientPackage['id']]['package_key']     = $clientPackage['package_key'];
                $arrClientPackages[$clientId][$clientPackage['id']]['billed']          = Cart::$billingPeriod[$clientPackage['billed']];
                $arrClientPackages[$clientId][$clientPackage['id']]['package_name']    = ucfirst($packagesRekeyed[$clientPackage['package_key']]['name']);
                $arrClientPackages[$clientId][$clientPackage['id']]['start_time']      = date('Y-m-d', strtotime($clientPackage['start_time']));
                $arrClientPackages[$clientId][$clientPackage['id']]['end_time']        = date('Y-m-d', strtotime($clientPackage['end_time']));
                $arrClientPackages[$clientId][$clientPackage['id']]['quantity']        = $clientPackage['quantity'];

                if(array_key_exists($clientPackage['id'],$arrClientPackageAvailability))
                { 
                    $arrClientPackages[$clientId][$clientPackage['id']]['assigned']    = array_filter($arrClientPackageAvailability[$clientPackage['id']]);
                }
                else
                {
                    $arrClientPackages[$clientId][$clientPackage['id']]['assigned']    = [];
                }
            }
        }
    }

    else
        {


            //fetch packages
            $packages           = Package::all()->toArray();
            $packagesRekeyed    = self::rekeyArray( $packages, 'key' );

            //fetch client_packages
        $clientId = $request->auth->parent_id;
            //fetch client_packages
            $clientPackages         = ClientPackage::where('client_id','=', $clientId)->where('end_time', '>=', date('Y-m-d h:i:s'))->get()->toArray();
            $clientPackagesRekeyed  = self::rekeyArray( $clientPackages, 'id' );
            //return $this->successResponse("All Available packages", $clientPackagesRekeyed );

            //fetch client_xxx.user_packages
            $userPackages = DB::connection('mysql_'.$clientId)->table('user_packages')->get()->toArray();
            $arrClientPackageAvailability = $this->getUsersByPackage( $userPackages );

            foreach ($clientPackagesRekeyed as $clientPackage)
            {
                $arrClientPackages[$clientId][$clientPackage['id']]['client_id']     = $clientId;
                
                $arrClientPackages[$clientId][$clientPackage['id']]['package_key']     = $clientPackage['package_key'];
                $arrClientPackages[$clientId][$clientPackage['id']]['billed']          = Cart::$billingPeriod[$clientPackage['billed']];
                $arrClientPackages[$clientId][$clientPackage['id']]['package_name']    = ucfirst($packagesRekeyed[$clientPackage['package_key']]['name']);
                $arrClientPackages[$clientId][$clientPackage['id']]['start_time']      = date('Y-m-d', strtotime($clientPackage['start_time']));
                $arrClientPackages[$clientId][$clientPackage['id']]['end_time']        = date('Y-m-d', strtotime($clientPackage['end_time']));
                $arrClientPackages[$clientId][$clientPackage['id']]['quantity']        = $clientPackage['quantity'];

                if(array_key_exists($clientPackage['id'],$arrClientPackageAvailability))
                { 
                    $arrClientPackages[$clientId][$clientPackage['id']]['assigned']    = array_filter($arrClientPackageAvailability[$clientPackage['id']]);
                }
                else
                {
                    $arrClientPackages[$clientId][$clientPackage['id']]['assigned']    = [];
                }
            }
       


        }
        return $this->successResponse("All Available packages", $arrClientPackages );
    }
/**
 * @OA\Get(
 *     path="/history-client-plans",
 *     summary="Get history of client plans",
 *     description="Fetch historical client plans based on user's level and client association.",
 *     tags={"Subscriptions"},
 *     security={{"Bearer":{}}},
 *     @OA\Parameter(
 *         name="Authorization",
 *         in="header",
 *         required=true,
 *         description="Authorization token",
 *         @OA\Schema(type="string")
 *     ),
 *     @OA\Response(
 *         response=200,
 *         description="Successful response with history client plans",
 *         @OA\JsonContent(
 *             type="object",
 *             @OA\Property(
 *                 property="message",
 *                 type="string",
 *                 example="History Client Package List"
 *             ),
 *             @OA\Property(
 *                 property="data",
 *                 type="array",
 *                 @OA\Items(
 *                     type="object",
 *                     @OA\Property(
 *                         property="client_id",
 *                         type="integer",
 *                         example=1
 *                     ),
 *                     @OA\Property(
 *                         property="package_key",
 *                         type="string",
 *                         example="package123"
 *                     ),
 *                     @OA\Property(
 *                         property="expiry_time",
 *                         type="string",
 *                         format="date-time",
 *                         example="2024-05-01T12:00:00"
 *                     ),
 *                     @OA\Property(
 *                         property="quantity",
 *                         type="integer",
 *                         example=5
 *                     ),
 *                     @OA\Property(
 *                         property="start_time",
 *                         type="string",
 *                         format="date",
 *                         example="2023-01-01"
 *                     ),
 *                     @OA\Property(
 *                         property="end_time",
 *                         type="string",
 *                         format="date",
 *                         example="2024-01-01"
 *                     )
 *                 )
 *             )
 *         )
 *     ),
 *     @OA\Response(
 *         response=400,
 *         description="Bad request, incorrect data or authorization error",
 *         @OA\JsonContent(
 *             @OA\Property(property="message", type="string", example="Invalid request")
 *         )
 *     )
 * )
 */
    public function historyClientPlans(Request $request)
    {
        if($request->auth->level > 7)
        {
            $historyClientPlans = ClientPackage::on("master")->where('expiry_time','<=',date('Y-m-d h:i:s'))->get()->all();
        }
        else
        {
            $historyClientPlans = ClientPackage::on("master")->where('client_id',$request->auth->parent_id)->where('expiry_time','<=',date('Y-m-d h:i:s'))->get()->all();
        }
    	
        return $this->successResponse("History Client Package List", $historyClientPlans);
    }

    public static function rekeyArray( $arrDataToRekey, $key )
    {
        if( empty( $arrDataToRekey ) ) return [];

        $arrDataToReturn = [];
        foreach ($arrDataToRekey as $arrSingleData )
        {
            $arrDataToReturn[$arrSingleData[$key]] = $arrSingleData;
        }
        return $arrDataToReturn;
    }

    public function getUsersByPackage( $userPackages )
    {
        if( empty( $userPackages ) ) return [];

        $arrToReturn = [];
        foreach ($userPackages as $userPackage)
        {
            $arrToReturn[$userPackage->client_package_id][] = $userPackage->user_id;
        }

        return $arrToReturn;
    }
}
