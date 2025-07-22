<?php

namespace App\Model\Master;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class ClientPackage extends Model
{
    protected $connection = 'master';
    protected $table = 'client_packages';

    CONST CLIENT_PACKAGE_EXPIRED = 1;

    protected $fillable = [
        "client_id",
        "package_key",
        "start_time",
        "end_time",
        "expiry_time",
        "billed",
        "prospect_id"
    ];

    public static $billingMapping = [
        "1" => "base_rate_monthly_billed",
        "2" => "base_rate_quarterly_billed",
        "3" => "base_rate_half_yearly_billed",
        "4" => "base_rate_yearly_billed"
    ];

    public static function getEndDateAsPerBillingCycle($intBillingCycle, $strStartDate = NULL)
    {
        $strEndDate = Carbon::now();
        if ($strStartDate == NULL) $strStartDate = Carbon::now();

        switch ($intBillingCycle) {
            case 1:
                $strEndDate = Carbon::now()->addMonth();
                break;
            case 2:
                $strEndDate = Carbon::now()->addMonths(3);
                break;
            case 3:
                $strEndDate = Carbon::now()->addMonths(6);
                break;
            case 4:
                $strEndDate = Carbon::now()->addYear();
                break;
        }
        return $strEndDate;
    }
}
