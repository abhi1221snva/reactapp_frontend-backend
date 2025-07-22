<?php

namespace App\Model\Client;

use Illuminate\Database\Eloquent\Model;

class FaxDid extends Model
{
    //
    protected $table = "fax_did";
    protected $fillable = ['userId'];
}
