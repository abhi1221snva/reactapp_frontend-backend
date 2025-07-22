<?php


namespace App\Model\Master;

use Illuminate\Database\Eloquent\Model;

class ForgotPasswordLink extends Model
{
    /**
     * The connection name for the model.
     */
    protected $connection = 'master';
    protected $table = "password_reset_email_varification";  
    protected $keyType = 'string';
      
}
