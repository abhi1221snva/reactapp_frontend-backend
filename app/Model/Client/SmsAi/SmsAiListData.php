<?php
namespace App\Model\Client\SmsAi;
use Illuminate\Database\Eloquent\Model;

class SmsAiListData extends Model
{
    public $timestamps = true;
    protected $table = "sms_ai_list_data";
    protected $fillable = [
        'option_1','option_2','option_3','option_4','option_5','option_6','option_7','option_8','option_9','option_10',
        'option_11','option_12','option_13','option_14','option_15','option_16','option_17','option_18','option_19','option_20',
        'option_21','option_22','option_23','option_24','option_25','option_26','option_27','option_28','option_29','option_30'
        ];
}