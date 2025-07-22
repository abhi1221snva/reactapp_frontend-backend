<?php
namespace App\Model\Client;

use App\Exceptions\RenderableException;
use Illuminate\Database\Eloquent\Model;

class SmtpSetting extends Model
{

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $table = "smtp_setting";

    protected $fillable = [
        'mail_driver',
        'mail_host',
        'mail_port',
        'mail_username',
        'mail_password',
        'mail_encryption',
        'sender_type',
        'campaign_id',
        'user_id',
        'from_email',
        'from_name'
    ];

    public static function getBySenderType($connection, string $senderType, int $senderTypeId = null): SmtpSetting
    {
        $sender = $senderType;
        $smtp = null;
        if ($senderType === "system") {
            $smtp = SmtpSetting::on($connection)->where("sender_type", "=", $senderType)->first();
        } elseif ($senderType === "campaign") {
            $smtp = SmtpSetting::on($connection)->where([
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
            $smtp = SmtpSetting::on($connection)->where([
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

        if (empty($smtp)) {
            throw new RenderableException("Failed to find SMTP setting for $senderType/$senderTypeId");
        }

        return $smtp;
    }
}
