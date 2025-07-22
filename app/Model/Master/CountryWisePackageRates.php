<?php

namespace App\Model\Master;
use Illuminate\Database\Eloquent\Model;
class CountryWisePackageRates extends Model
{
    public $timestamps = false;
    protected $table = "country_wise_package_rate";
    protected $fillable = ['id', 'package_key', 'phone_code', 'call_rate_per_minute', 'rate_six_by_six_sec', 'rate_per_sms','rate_per_did','rate_per_fax','rate_per_email','title_name'];

}
