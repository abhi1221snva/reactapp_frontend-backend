<?php


namespace App\Model\Master;


use App\Model\Traits\HasCompositePrimaryKey;
use Illuminate\Database\Eloquent\Model;

class VoiceAi extends Model
{

    /**
     * The connection name for the model.
     */
    protected $connection = 'master';
    protected $table = 'user_wise_voice_ai';
    protected $fillable = ['user_id', 'extension','speech_text','language','voice_name','file_name','ivr_desc','prompt_option'];
}
