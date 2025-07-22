<?php

namespace App\Model\Master;

use Illuminate\Database\Eloquent\Model;

class GoogleLanguage extends Model
{
    /**
     * The connection name for the model.
     */
    protected $connection = 'master';
    
    public $timestamps = false;

    protected $table = "google_language";

    protected $fillable = ['id', 'language', 'voice_type', 'language_code', 'voice_name', 'ssml_gender'];
}
