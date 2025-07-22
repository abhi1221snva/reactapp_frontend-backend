<?php

namespace App\Model\Master;

use Illuminate\Database\Eloquent\Model;

class DomainList extends Model
{
    /**
     * The connection name for the model.
     */
    protected $connection = 'master';
    protected $table = 'domain_whitelist';
    protected $fillable = ['domain_name', 'client_id'];

}
