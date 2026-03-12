<?php

namespace App\Model\Master;

use Illuminate\Database\Eloquent\Model;

class Client extends Model
{
    /**
     * The connection name for the model.
     */
    protected $connection = 'master';

    protected $fillable = ['id', 'company_name', 'address_1', 'address_2', 'logo', 'trunk','sms','fax','chat','webphone','sms_plateform','enable_2fa','api_key','mca_crm','ringless','callchex','predictive_dial','sendgrid_key','call_matrix_api_key','call_matrix_api_url','call_matrix_status'];

    const RECORD_SAVED          = 1;
    const ADMIN_ASSIGNED        = 2;
    const SAVE_CONNECTION       = 3;
    const MIGRATE_SEED          = 4;
    const ASSIGN_ASTERISK_SERVER = 5;
    const FULLY_PROVISIONED     = 6;  // storage + settings + admin user created

    public function getAsteriskServers()
    {
        $asteriskServerList = [];
        $servers = $this->servers;
        foreach ( $servers as $server ) {
            $asteriskServerList[$server->server_id] = $server->toArray();
        }
        return $asteriskServerList;
    }

    public function servers()
    {
        return $this->hasMany("App\Model\Master\ClientServers");
    }
}
