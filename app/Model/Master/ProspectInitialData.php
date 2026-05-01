<?php

namespace App\Model\Master;

use Illuminate\Database\Eloquent\Model;

class ProspectInitialData extends Model
{
    /**
     * The connection name for the model.
     */
    protected $connection = 'master';

    protected $table = 'prospect_initial_data';

    protected $fillable = [
        'name', 'first_name', 'last_name', 'email', 'company_name', 'password', 'phone_number', 'country_code',
    ];
}
