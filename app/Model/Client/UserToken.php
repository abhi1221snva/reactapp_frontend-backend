<?php
namespace App\Model\Client;
use Illuminate\Database\Eloquent\Model;

class UserToken extends Model
{

    //protected $guarded = ['userId'];
     protected $primaryKey = 'userId';

    protected $table = "user_token";
    protected $fillable = [
        'deviceToken',
        'deviceType',
		'push_token'
    ];
}
