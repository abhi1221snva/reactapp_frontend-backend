<?php
namespace App\Model\Client;
use Illuminate\Database\Eloquent\Model;

class Comments extends Model
{
    public $timestamps = false;

    protected $table = "comment";
}
