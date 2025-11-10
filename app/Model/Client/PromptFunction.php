<?php

namespace App\Model\Client;

use Illuminate\Database\Eloquent\Model;

class PromptFunction extends Model
{
    protected $fillable = [
        'prompt_id',
        'user_id',
        'type',
        'name',
        'message',
        'phone',
        'curl_request',
        'curl_response',
        'api_method',
        'api_url',
        'api_body',
        'api_response',
        'content',
        'description'
    ];

    public function prompt()
    {
        return $this->belongsTo(Prompt::class);
    }
}
