<?php

namespace App\Model\Client;

use Illuminate\Database\Eloquent\Model;
use \App\Model\User;

class Prompt extends Model
{
    protected $fillable = [
        'user_id',
        'title',
        'description',
        'initial_greeting',
        'voice_name',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function functions()
    {
        return $this->hasMany(PromptFunction::class);
    }
}
