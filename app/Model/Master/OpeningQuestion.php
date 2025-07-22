<?php

namespace App\Model\Master;

use Illuminate\Database\Eloquent\Model;

class OpeningQuestion extends Model
{
    protected $connection = 'master';
    protected $table = 'opening_questions';

    public static $arrQuestionsHeadingsByPath = [
        "add-extension" => "Extensions",
        "show-buy-did" => "Phone Numbers",
        "ivr" => "Welcome Message",
        "ivr-menu" => "Welcome Msg Options",
        "did/holidays" => "Holidays",
        "did/call-timings" => "Call Timings",
        "smtp" => "E-Mail Configuration",
        "disposition" => "Dispositions",
        "add-campaign" => "Campaigns",
        "label" => "Labels",
        "list" => "Lists",
        "email-template" => "Email Templates",
        "sms-templete" => "Text Templates",
        "marketing-campaigns" => "Marketing Campaigns"
    ];

    public static $arrQuestionsIconsByPath = [
        "add-extension" => "fa fa-users",
        "show-buy-did" => "fa fa-phone",
        "ivr" => "fa fa-send",
        "ivr-menu" => "fa fa-send-o",
        "did/holidays" => "fa fa-phone",
        "did/call-timings" => "fa fa-hourglass",
        "smtp" => "fa fa-envelope",
        "disposition" => "fa fa-tasks",
        "add-campaign" => "fa fa-users",
        "label" => "fa fa-tags",
        "list" => "fa fa-list",
        "email-template" => "fa fa-envelope",
        "sms-templete" => "fa fa-send",
        "marketing-campaigns" => "fa fa-users"
    ];

}
