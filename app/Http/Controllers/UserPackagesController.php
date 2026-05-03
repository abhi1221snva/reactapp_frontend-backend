<?php

namespace App\Http\Controllers;

use App\Model\Master\Package;
use App\Model\Role;
use Illuminate\Http\Request;
use App\Model\User;
use App\Model\Master\ClientPackage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class UserPackagesController extends Controller
{
    /**
 * @OA\Get(
 *     path="/user-packages",
 *     summary="Get users and their assigned packages",
 *     description="Fetch all users and their assigned packages based on client and user roles.",
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
 *         description="Successful response with users and their assigned packages",
 *         @OA\JsonContent(
 *             type="object",
 *             @OA\Property(
 *                 property="message",
 *                 type="string",
 *                 example="All Users packages"
 *             ),
 *             @OA\Property(
 *                 property="data",
 *                 type="array",
 *                 @OA\Items(
 *                     type="object",
 *                     @OA\Property(
 *                         property="user_id",
 *                         type="integer",
 *                         example=1
 *                     ),
 *                     @OA\Property(
 *                         property="client_id",
 *                         type="integer",
 *                         example=101
 *                     ),
 *                     @OA\Property(
 *                         property="first_name",
 *                         type="string",
 *                         example="John"
 *                     ),
 *                     @OA\Property(
 *                         property="last_name",
 *                         type="string",
 *                         example="Doe"
 *                     ),
 *                     @OA\Property(
 *                         property="role",
 *                         type="string",
 *                         example="Admin"
 *                     ),
 *                     @OA\Property(
 *                         property="package_key",
 *                         type="string",
 *                         nullable=true,
 *                         example="premium_package"
 *                     ),
 *                     @OA\Property(
 *                         property="package_id",
 *                         type="integer",
 *                         nullable=true,
 *                         example=202
 *                     ),
 *                     @OA\Property(
 *                         property="package_name",
 *                         type="string",
 *                         nullable=true,
 *                         example="Premium Package"
 *                     ),
 *                     @OA\Property(
 *                         property="quantity",
 *                         type="integer",
 *                         nullable=true,
 *                         example=5
 *                     ),
 *                     @OA\Property(
 *                         property="start_time",
 *                         type="string",
 *                         format="date",
 *                         nullable=true,
 *                         example="2024-01-01"
 *                     ),
 *                     @OA\Property(
 *                         property="end_time",
 *                         type="string",
 *                         format="date",
 *                         nullable=true,
 *                         example="2024-12-31"
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
    public function getUsersPackages(Request $request)
    {
        $arrUserPackages = [];

        //fetch users
        $clientId = $request->auth->parent_id;
        $baseParentId = $request->auth->base_parent_id;

        // If base parent id is same then show Super Admin.
        if($baseParentId == $clientId) {
            $users = User::where('parent_id', '=', $clientId)->where('is_deleted', '=', 0)->get()->toArray();
        } else{
            $users = User::where('parent_id', '=', $clientId)->where('is_deleted', '=' , 0)->where('user_level', '<=' , 7)->get()->toArray();
        }

        //fetch roles
        $roles          = Role::all()->toArray();
        $rolesRekeyed   = self::rekeyArray( $roles, 'id' );

        //fetch packages
        $packages           = Package::all()->toArray();
        $packagesRekeyed    = self::rekeyArray( $packages, 'key' );

        //fetch client_packages
        $clientPackages         = ClientPackage::where('client_id','=', $clientId)->where('end_time', '>=', date('Y-m-d h:i:s'))->get()->toArray();
        $arrClientPackagesRekeyed  = self::rekeyArray( $clientPackages, 'id' );

        //fetch client_xxx.user_packages
        $userPackages = DB::connection('mysql_'.$clientId)->table('user_packages')->get()->toArray();

        foreach($users as $arrUser)
        {
            $arrUserPackages[$arrUser['id']]['user_id']    = $arrUser['id'];
            $arrUserPackages[$arrUser['id']]['client_id']  = $clientId;
            $arrUserPackages[$arrUser['id']]['first_name'] = $arrUser['first_name'];
            $arrUserPackages[$arrUser['id']]['last_name']  = $arrUser['last_name'];
            $arrUserPackages[$arrUser['id']]['role']       = ucfirst( str_replace('_', ' ', $rolesRekeyed[$arrUser['role']]['name'] ) );

            $intClientPackageId = $this->getPackageAssignedToUser($userPackages, $arrUser['id'], $arrClientPackagesRekeyed);

            if(!$intClientPackageId || !array_key_exists($intClientPackageId,$arrClientPackagesRekeyed))
            {
                $arrUserPackages[$arrUser['id']]['package_key']     = null;
                $arrUserPackages[$arrUser['id']]['package_id']      = null;
                $arrUserPackages[$arrUser['id']]['package_name']    = null;
                $arrUserPackages[$arrUser['id']]['quantity']        = null;
                $arrUserPackages[$arrUser['id']]['start_time']      = null;
                $arrUserPackages[$arrUser['id']]['end_time']        = null;
            } else {
                $strPackageKey = $arrClientPackagesRekeyed[$intClientPackageId]['package_key'];
                $arrUserPackages[$arrUser['id']]['package_key']     = $strPackageKey;
                $arrUserPackages[$arrUser['id']]['package_id']      = $intClientPackageId;
                $arrUserPackages[$arrUser['id']]['package_name']    = ucfirst($packagesRekeyed[$strPackageKey]['name']);
                $arrUserPackages[$arrUser['id']]['quantity']        = $arrClientPackagesRekeyed[$intClientPackageId]['quantity'];
                $arrUserPackages[$arrUser['id']]['start_time']      = date('Y-m-d', strtotime($arrClientPackagesRekeyed[$intClientPackageId]['start_time']));
                $arrUserPackages[$arrUser['id']]['end_time']        = date('Y-m-d', strtotime($arrClientPackagesRekeyed[$intClientPackageId]['end_time']));
            }
        }
        return $this->successResponse("All Users packages", array_values( $arrUserPackages ) );
    }

    /**
 * @OA\Get(
 *     path="/client-packages",
 *     summary="Get all available packages for the client",
 *     tags={"Subscriptions"},
 *     security={{"Bearer":{}}},
 *     @OA\Response(
 *         response=200,
 *         description="All Available packages",
 *         @OA\JsonContent(
 *             type="object",
 *             @OA\Property(property="status", type="boolean", example=true),
 *             @OA\Property(property="message", type="string", example="All Available packages"),
 *             @OA\Property(
 *                 property="data",
 *                 type="object",
 *                 additionalProperties={
 *                     @OA\Property(property="package_key", type="string", example="basic_package"),
 *                     @OA\Property(property="package_name", type="string", example="Basic Package"),
 *                     @OA\Property(property="start_time", type="string", format="date", example="2024-01-01"),
 *                     @OA\Property(property="end_time", type="string", format="date", example="2024-12-31"),
 *                     @OA\Property(property="quantity", type="integer", example=10),
 *                     @OA\Property(
 *                         property="assigned",
 *                         type="array",
 *                         @OA\Items(type="integer", example=123)
 *                     )
 *                 }
 *             )
 *         )
 *     ),
 *     @OA\Response(
 *         response=401,
 *         description="Unauthorized"
 *     ),
 *     @OA\Response(
 *         response=500,
 *         description="Server error"
 *     )
 * )
 */
public function getClientPackages(Request $request)
{
    $arrClientPackages = [];

    // fetch packages
    $packages           = Package::all()->toArray();
    $packagesRekeyed    = self::rekeyArray($packages, 'key');

    // fetch client_packages
    $clientId = $request->auth->parent_id;
    $clientPackages = ClientPackage::where('client_id', '=', $clientId)
        ->where('end_time', '>=', date('Y-m-d h:i:s'))
        ->get()
        ->toArray();

    // fetch client_xxx.user_packages
    $userPackages = DB::connection('mysql_'.$clientId)->table('user_packages')->get()->toArray();
    $arrClientPackageAvailability = $this->getUsersByPackage($userPackages);

    foreach ($clientPackages as $clientPackage) {
        $packageData = [
            'id'            => $clientPackage['id'],
            'package_key'   => $clientPackage['package_key'],
            'package_name'  => ucfirst($packagesRekeyed[$clientPackage['package_key']]['name']),
            'start_time'    => date('Y-m-d', strtotime($clientPackage['start_time'])),
            'end_time'      => date('Y-m-d', strtotime($clientPackage['end_time'])),
            'quantity'      => $clientPackage['quantity'],
            'assigned'      => array_key_exists($clientPackage['id'], $arrClientPackageAvailability)
                                ? array_filter($arrClientPackageAvailability[$clientPackage['id']])
                                : []
        ];

        $arrClientPackages[] = $packageData; // push into list instead of using id as key
    }

    return $this->successResponse("All Available packages", $arrClientPackages);
}

    // public function getClientPackages(Request $request)
    // {
    //     $arrClientPackages = [];

    //     //fetch packages
    //     $packages           = Package::all()->toArray();
    //     $packagesRekeyed    = self::rekeyArray( $packages, 'key' );

    //     //fetch client_packages
    //     $clientId = $request->auth->parent_id;

    //     //fetch client_packages
    //     $clientPackages         = ClientPackage::where('client_id','=', $clientId)->where('end_time', '>=', date('Y-m-d h:i:s'))->get()->toArray();
    //     $clientPackagesRekeyed  = self::rekeyArray( $clientPackages, 'id' );

    //     //fetch client_xxx.user_packages
    //     $userPackages = DB::connection('mysql_'.$clientId)->table('user_packages')->get()->toArray();
    //     $arrClientPackageAvailability = $this->getUsersByPackage( $userPackages );

    //     foreach ($clientPackagesRekeyed as $clientPackage)
    //     {
    //         $arrClientPackages[$clientPackage['id']]['package_key']     = $clientPackage['package_key'];
    //         $arrClientPackages[$clientPackage['id']]['package_name']    = ucfirst($packagesRekeyed[$clientPackage['package_key']]['name']);
    //         $arrClientPackages[$clientPackage['id']]['start_time']      = date('Y-m-d', strtotime($clientPackage['start_time']));
    //         $arrClientPackages[$clientPackage['id']]['end_time']        = date('Y-m-d', strtotime($clientPackage['end_time']));
    //         $arrClientPackages[$clientPackage['id']]['quantity']        = $clientPackage['quantity'];

    //         if(array_key_exists($clientPackage['id'],$arrClientPackageAvailability)){
    //             $arrClientPackages[$clientPackage['id']]['assigned']    = array_filter($arrClientPackageAvailability[$clientPackage['id']]);
    //         } else {
    //             $arrClientPackages[$clientPackage['id']]['assigned']    = [];
    //         }
    //     }

    //     return $this->successResponse("All Available packages", $arrClientPackages );
    // }
/**
 * @OA\Get(
 *     path="/user-package-urls/{userId}",
 *     summary="Get user's current package modules",
 *     tags={"Subscriptions"},
 *     security={{"Bearer":{}}},
 *     @OA\Parameter(
 *         name="userId",
 *         in="path",
 *         required=true,
 *         description="ID of the user",
 *         @OA\Schema(type="integer", example=123)
 *     ),
 *     @OA\Response(
 *         response=200,
 *         description="Current package modules fetched successfully",
 *         @OA\JsonContent(
 *             @OA\Property(property="status", type="boolean", example=true),
 *             @OA\Property(property="message", type="string", example="Current package"),
 *             @OA\Property(
 *                 property="data",
 *                 type="array",
 *                 @OA\Items(
 *                     type="object",
 *                     @OA\Property(property="module_name", type="string", example="dashboard"),
 *                     @OA\Property(property="url", type="string", example="/dashboard")
 *                 )
 *             )
 *         )
 *     ),
 *     @OA\Response(
 *         response=500,
 *         description="Failed to get user current package details"
 *     )
 * )
 */

    public function getUserPackageDetailsUrls(Request $request, int $userId){
        $arrUserPackage = [];

        //fetch client_xxx.user_packages
        $userPackages = DB::connection('mysql_'.$request->auth->parent_id)->table('user_packages')->where("user_id", "=", $userId)->get()->toArray();

        //fetch client_packages
        $clientPackages         = ClientPackage::where('id','=', $userPackages[0]->client_package_id)->get()->toArray();

        //return $this->successResponse("Current package", [$clientPackages[0]['package_key']] );

        //$clientPackagesRekeyed  = self::rekeyArray( $clientPackages, 'id' );

        //fetch packages
        $packages           = Package::all()->toArray();
        $packagesRekeyed    = self::rekeyArray( $packages, 'key' );

        return $this->successResponse("Current package", $packagesRekeyed[$clientPackages[0]['package_key']]['modules'] );


        try {
            if(empty($userPackages)){
                $arrUserPackage['package_name'] = '';
                $arrUserPackage['days_remaining'] = 0;
                $arrUserPackage['expired'] = TRUE;
            } else{
                $arrClientPackage = $clientPackagesRekeyed[$userPackages[0]->client_package_id];
                if ($arrClientPackage['expiry_time'] <= Carbon::now()) {
                    $arrUserPackage['package_name'] = strtolower($packagesRekeyed[$arrClientPackage['package_key']]['name']);
                    $arrUserPackage['expired'] = TRUE;
                } else{
                    $created = new Carbon($arrClientPackage['expiry_time']);
                    $arrUserPackage['package_name'] = strtolower($packagesRekeyed[$arrClientPackage['package_key']]['name']);
                    $arrUserPackage['days_remaining'] = $created->diff(Carbon::now())->days;
                    $arrUserPackage['expired'] = FALSE;
                }
            }
        return $this->successResponse("Current package", $arrUserPackage );

        } catch (\Throwable $exception) {
            return $this->failResponse("Failed to get user current package details", [], $exception);
        }
    }
    /**
 * @OA\Post(
 *     path="/user-package/update/{packageKey}",
 *     summary="Assign a package to a user",
 *     tags={"Subscriptions"},
 *     security={{"Bearer":{}}},
 *     @OA\Parameter(
 *         name="packageKey",
 *         in="path",
 *         description="The key of the package to be assigned",
 *         required=true,
 *         @OA\Schema(type="string", example="basic_package")
 *     ),
 *     @OA\RequestBody(
 *         required=true,
 *         @OA\JsonContent(
 *             required={"user_id", "client_id"},
 *             @OA\Property(property="user_id", type="integer", example=123),
 *             @OA\Property(property="client_id", type="integer", example=45)
 *         )
 *     ),
 *     @OA\Response(
 *         response=200,
 *         description="User Package updated successfully",
 *         @OA\JsonContent(
 *             @OA\Property(property="status", type="boolean", example=true),
 *             @OA\Property(property="message", type="string", example="User Package updated successfully"),
 *             @OA\Property(property="data", type="array", @OA\Items())
 *         )
 *     ),
 *     @OA\Response(
 *         response=400,
 *         description="Validation error"
 *     ),
 *     @OA\Response(
 *         response=500,
 *         description="Failed to update package"
 *     )
 * )
 */

    public function updateUserPackage( Request $request, string $packageKey )
    {
        $this->validate($request, [
            'user_id' => 'required|numeric|int',
            'client_id' => 'required|numeric|int',
        ]);

        $input = $request->all();
        $clientPackage = ClientPackage::where('client_id','=', $input['client_id'])->where('package_key','=', $packageKey)->where('end_time', '>=', date('Y-m-d h:i:s'))->first();

        try {
            DB::connection('mysql_'.$input['client_id'])->statement("UPDATE user_packages SET user_id=".$input['user_id']." WHERE client_package_id=".$clientPackage->id." and user_id IS NULL LIMIT 1");
            Cache::forget("user.package.{$input['user_id']}.{$input['client_id']}");
            Cache::forget("user.components.{$input['user_id']}.{$input['client_id']}");
            return $this->successResponse("User Package updated successfully", []);

        } catch (\Throwable $exception) {
            return $this->failResponse("Failed to update package", [], $exception);
        }
    }
/**
 * @OA\Post(
 *     path="/user-package/delete/{packageKey}",
 *     summary="Remove a package assignment from a user",
 *     tags={"Subscriptions"},
 *     security={{"Bearer":{}}},
 *     @OA\Parameter(
 *         name="packageKey",
 *         in="path",
 *         description="The key of the package to remove from the user",
 *         required=true,
 *         @OA\Schema(type="string", example="basic_package")
 *     ),
 *     @OA\RequestBody(
 *         required=true,
 *         @OA\JsonContent(
 *             required={"user_id", "client_id"},
 *             @OA\Property(property="user_id", type="integer", example=123),
 *             @OA\Property(property="client_id", type="integer", example=45)
 *         )
 *     ),
 *     @OA\Response(
 *         response=200,
 *         description="User Package removed successfully",
 *         @OA\JsonContent(
 *             @OA\Property(property="status", type="boolean", example=true),
 *             @OA\Property(property="message", type="string", example="User Package removed successfully"),
 *             @OA\Property(
 *                 property="data",
 *                 type="array",
 *                 @OA\Items(type="integer", example=123)
 *             )
 *         )
 *     ),
 *     @OA\Response(
 *         response=400,
 *         description="Validation error"
 *     ),
 *     @OA\Response(
 *         response=500,
 *         description="Failed to update package"
 *     )
 * )
 */

    public function deleteUserPackage( Request $request, string $packageKey )
    {
        $this->validate($request, [
            'user_id' => 'required|numeric|int',
            'client_id' => 'required|numeric|int',
        ]);

        $input = $request->all();
        $clientPackage = ClientPackage::where('client_id','=', $input['client_id'])->where('package_key','=', $packageKey)->where('end_time', '>=', date('Y-m-d h:i:s'))->first();

        try {
            DB::connection('mysql_'.$input['client_id'])->statement("UPDATE user_packages SET user_id=NULL WHERE client_package_id=".$clientPackage->id." AND user_id=" . $input['user_id'] ." ");
            Cache::forget("user.package.{$input['user_id']}.{$input['client_id']}");
            Cache::forget("user.components.{$input['user_id']}.{$input['client_id']}");
            return $this->successResponse("User Package removed successfully", [$input['client_id'],$clientPackage->id,$input['user_id']]);

        } catch (\Throwable $exception) {
            return $this->failResponse("Failed to update package", [], $exception);
        }
    }
/**
 * @OA\Get(
 *     path="/user-package/{userId}",
 *     summary="Get current package details for a user",
 *     tags={"Subscriptions"},
 *     security={{"Bearer":{}}},
 *     @OA\Parameter(
 *         name="userId",
 *         in="path",
 *         description="ID of the user to retrieve package details for",
 *         required=true,
 *         @OA\Schema(type="integer", example=123)
 *     ),
 *     @OA\Response(
 *         response=200,
 *         description="Current package information",
 *         @OA\JsonContent(
 *             @OA\Property(property="status", type="boolean", example=true),
 *             @OA\Property(property="message", type="string", example="Current package"),
 *             @OA\Property(
 *                 property="data",
 *                 type="object",
 *                 @OA\Property(property="package_name", type="string", example="premium"),
 *                 @OA\Property(property="days_remaining", type="integer", example=15),
 *                 @OA\Property(property="expired", type="boolean", example=false)
 *             )
 *         )
 *     ),
 *     @OA\Response(
 *         response=404,
 *         description="Package not found or no user package assigned"
 *     ),
 *     @OA\Response(
 *         response=500,
 *         description="Failed to get user current package details"
 *     )
 * )
 */

    public function getUserPackageDetails(Request $request, int $userId){
        $arrUserPackage = [];

        //fetch client_xxx.user_packages
        $userPackages = DB::connection('mysql_'.$request->auth->parent_id)->table('user_packages')->where("user_id", "=", $userId)->get()->toArray();

        //fetch client_packages
        $clientPackages         = ClientPackage::where('client_id','=', $request->auth->parent_id)->get()->toArray();
        $clientPackagesRekeyed  = self::rekeyArray( $clientPackages, 'id' );

        //fetch packages
        $packages           = Package::all()->toArray();
        $packagesRekeyed    = self::rekeyArray( $packages, 'key' );

        try {
            if(empty($userPackages)){
                $arrUserPackage['package_name'] = '';
                $arrUserPackage['days_remaining'] = 0;
                $arrUserPackage['expired'] = TRUE;
            } else{
                $arrClientPackage = $clientPackagesRekeyed[$userPackages[0]->client_package_id];
                if ($arrClientPackage['expiry_time'] <= Carbon::now()) {
                    $arrUserPackage['package_name'] = strtolower($packagesRekeyed[$arrClientPackage['package_key']]['name']);
                    $arrUserPackage['expired'] = TRUE;
                } else{
                    $created = new Carbon($arrClientPackage['expiry_time']);
                    $arrUserPackage['package_name'] = strtolower($packagesRekeyed[$arrClientPackage['package_key']]['name']);
                    $arrUserPackage['days_remaining'] = $created->diff(Carbon::now())->days;
                    $arrUserPackage['expired'] = FALSE;
                }
            }
        return $this->successResponse("Current package", $arrUserPackage );

        } catch (\Throwable $exception) {
            return $this->failResponse("Failed to get user current package details", [], $exception);
        }
    }
/**
 * @OA\Get(
 *     path="/client-packages/trial",
 *     summary="Get trial package details for the authenticated client",
 *     tags={"Subscriptions"},
 *     security={{"Bearer":{}}},
 *     @OA\Response(
 *         response=200,
 *         description="Trial package details fetched successfully",
 *         @OA\JsonContent(
 *             @OA\Property(property="status", type="boolean", example=true),
 *             @OA\Property(property="message", type="string", example="Trial Package Details"),
 *             @OA\Property(
 *                 property="data",
 *                 type="object",
 *                 @OA\Property(property="expired", type="boolean", example=false),
 *                 @OA\Property(property="days_remaining", type="integer", example=5),
 *                 @OA\Property(property="count", type="integer", example=3)
 *             )
 *         )
 *     ),
 *     @OA\Response(
 *         response=500,
 *         description="Failed to fetch trial package details"
 *     )
 * )
 */

    public function getTrialPackageDetails(Request $request){
        $arrTrialPackageDetails['expired'] = TRUE;
        $arrTrialPackageDetails['days_remaining'] = 0;

        // If the client has an active Stripe subscription, they are not on trial
        $client = \App\Model\Master\Client::find($request->auth->parent_id);
        if ($client && $client->stripe_subscription_id && in_array($client->subscription_status, ['active', 'past_due'])) {
            $arrTrialPackageDetails['expired'] = FALSE;
            $arrTrialPackageDetails['days_remaining'] = 0;
            $arrTrialPackageDetails['count'] = 2; // signal non-trial to frontend
            return $this->successResponse("Trial Package Details", $arrTrialPackageDetails);
        }

        $arrAllClientPackages = ClientPackage::where('client_id','=', $request->auth->parent_id)->get()->toArray();
        $arrTrialPackageDetails['count'] = count($arrAllClientPackages);

        foreach($arrAllClientPackages as $arrAllClientPackage){
            if($arrAllClientPackage['package_key'] == Package::TRIAL_PACKAGE_KEY){
                if ($arrAllClientPackage['expiry_time'] <= Carbon::now()) {
                    $arrTrialPackageDetails['expired'] = TRUE;
                } else{
                    $created = new Carbon($arrAllClientPackage['expiry_time']);
                    $arrTrialPackageDetails['days_remaining'] = $created->diff(Carbon::now())->days;
                    $arrTrialPackageDetails['expired'] = FALSE;
                }
            }
        }

        return $this->successResponse("Trial Package Details", $arrTrialPackageDetails );
    }

    public static function getPackageAssignedToUser($userPackages, $userId, $arrClientPackagesRekeyed)
    {
        if( empty( $userPackages ) ) return false;

        foreach ($userPackages as $userPackage)
        {
            if( $userPackage->user_id == $userId ){
                if(!array_key_exists($userPackage->client_package_id, $arrClientPackagesRekeyed)){
                    continue;
                }
                return $userPackage->client_package_id;
            }
        }
        return false;
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
}
