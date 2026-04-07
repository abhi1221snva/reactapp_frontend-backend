<?php


namespace App\Services;

use App\Model\Client\wallet;
use App\Model\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CallService
{
    private $extensionNo;
    private $phoneNo;
    private $cdrId;
    private $parentId;

    public function __construct($intExtensionNo, $intPhoneNo, $intCdrId, $intParentId = null)
    {
        $this->extensionNo = $intExtensionNo;
        $this->phoneNo = $intPhoneNo;
        $this->cdrId = $intCdrId;
        $this->parentId = $intParentId;
    }

    public function chargeForCall($intTotalCallDuration, $strToken)
    {
        if ($strToken != env('CALL_CHARGE_SECRET')) {
            Log::warning("Incorrect Token: charge for call", [
                "extensionNo" => $this->extensionNo,
                "phoneNo" => $this->phoneNo,
                "cdrId" => $this->cdrId,
                "intTotalCallDuration" =>$intTotalCallDuration,
                "strToken" => $strToken
            ]);
            return false;
        }

        $intTotalCharge = $billableCharge = $currencyCode = $clientPackageId = NULL;
        //fetch user — scoped by parent_id (tenant) to prevent cross-tenant collisions
        $userQuery = User::where(['extension' => $this->extensionNo, 'is_deleted' => 0]);
        if ($this->parentId) {
            $userQuery->where('parent_id', $this->parentId);
        }
        $user = $userQuery->first();

        //for alt extension if extension not found
        if(empty($user))
        {
            $altQuery = User::where(['alt_extension' => $this->extensionNo, 'is_deleted' => 0]);
            if ($this->parentId) {
                $altQuery->where('parent_id', $this->parentId);
            }
            $user = $altQuery->first();
        }

        //end alt_extension
        
        if (!$user) {
            return false;
        }

        //get package
        $package = $user->getAssignedUserPackage(true);
        $billableMinutes = $intTotalCallDuration;

        //Calculate call charges
        $intTotalCharge = $billableCharge = $intTotalCallDuration * $package->call_rate_per_minute;

        if(empty($package)){
            //No charge for Admin
            $billableMinutes = 0;
            $billableCharge = 0;

        } else {
            if($package->free_call_minutes >= $intTotalCallDuration){
                //Deduct from free balance
                DB::connection('mysql_'.$user->parent_id)->table('user_packages')->where('id',$package->user_package_id)->decrement('free_call_minutes',$intTotalCallDuration);
                $billableMinutes = 0;
                $billableCharge = 0;

            } else {
                if ($package->free_call_minutes > 0){
                    //Deduct remaining free balance
                    $billableMinutes = $intTotalCallDuration - $package->free_call_minutes;
                    $billableCharge = $billableMinutes * $package->call_rate_per_minute;
                    DB::connection('mysql_'.$user->parent_id)->table('user_packages')->where('id',$package->user_package_id)->decrement('free_call_minutes',$package->free_call_minutes);
                }

                //Deduct amount from client_xxx.wallet
                wallet::debitCharge($billableCharge, $user->parent_id, $package->currency_code);
            }
            $currencyCode = $package->currency_code;
            $clientPackageId = $package->id;
        }

        //update client.cdr
        $strSql = "UPDATE cdr SET charge = " . $intTotalCharge . ",
                    currency_code='" . $currencyCode . "',
                    client_package_id = " . $clientPackageId . ",
                    user_id = " . $user->id . ",
                    billable_minutes = " . $billableMinutes .",
                    billable_charge = " . $billableCharge . "
                    WHERE id = " . $this->cdrId;
        DB::connection('mysql_' . $user->parent_id)->update($strSql);

        return true;
    }
}
