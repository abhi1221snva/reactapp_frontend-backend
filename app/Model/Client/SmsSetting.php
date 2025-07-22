<?php
namespace App\Model\Client;

use App\Exceptions\RenderableException;
use Illuminate\Database\Eloquent\Model;

class SmsSetting extends Model
{

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $table = "sms_setting";

    protected $fillable = [
        'sms_url',
        'sender_name',
        'api_key',
        'status',
        'user_id',
        'sender_type',
        'campaign_id'
    ];

    public static function getBySenderType($connection, string $senderType, int $senderTypeId = null): SmsSetting
    {
        $sms = null;
        if ($senderType === "system") {
            $sms = SmsSetting::on($connection)->where("sender_type", "=", $senderType)->first();
        } elseif ($senderType === "campaign") {
            $sms = SmsSetting::on($connection)->where([
                [
                    "sender_type",
                    "=",
                    $senderType
                ],
                [
                    "campaign_id",
                    "=",
                    $senderTypeId
                ]
            ])->first();
        } elseif ($senderType === "user") {
            $sms = SmtpSetting::on($connection)->where([
                [
                    "sender_type",
                    "=",
                    $senderType
                ],
                [
                    "user_id",
                    "=",
                    $senderTypeId
                ]
            ])->first();
        }

        if (empty($sms)) {
            throw new RenderableException("Failed to find SMS setting for $senderType/$senderTypeId");
        }

        return $sms;
    }
}
