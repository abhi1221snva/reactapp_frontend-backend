<?php


namespace App\Model\Master;

use Illuminate\Database\Eloquent\Model;

class PasswordReset extends Model
{
    /**
     * The connection name for the model.
     */
    protected $connection = 'master';
    protected $fillable = [
        "email",
        "token",  
    ];
}
