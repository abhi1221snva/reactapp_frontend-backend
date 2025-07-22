<?php


namespace App\Model\Master;

use Illuminate\Database\Eloquent\Model;

class Package extends Model
{
    /**
     * The connection name for the model.
     */
    protected $connection = 'master';
    protected $primaryKey = 'key';
    protected $keyType = 'string';

    const TRIAL_PACKAGE_KEY  = '588703ba-e78a-430f-8872-bb088dc1abba';

    protected $casts = [
        "show_on" => "array",
        "modules" => "array"
    ];

    protected $fillable = [
        "key",
        "name",
        "description",
        "is_active",
        "applicable_for",      #1 - b2b, 2 - b2c, 3 - both
        "show_on",   #website, portal
        "modules",
        "currency_code",
        "base_rate_monthly_billed",
        "base_rate_quarterly_billed",
        "base_rate_half_yearly_billed",
        "base_rate_yearly_billed",
        "call_rate_per_minute",
        "rate_per_sms",
        "rate_per_did",
        "rate_per_fax",
        "rate_per_email",
        "free_call_minute_monthly",
        "free_sms_monthly",
        "free_fax_monthly",
        "free_emails_monthly",
        "free_did_monthly"
    ];
}
