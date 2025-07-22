<?php


namespace App\Model\Client;


use Illuminate\Database\Eloquent\Model;

class VoiceMailDrop extends Model
{
    /**
     * Indicates if the model should be timestamped.
     *
     * @var bool
     */
    public $timestamps = false;
    protected $table = "voicemail_drop";
    protected $fillable = ["ivr_id", "ann_id","ivr_desc","speech_text","language","voice_name","user_id","prompt_option"];

}
